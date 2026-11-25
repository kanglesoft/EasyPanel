<?php
/**
 * notify.api.php —— 通知渠道发送引擎（功能项 11）
 *
 * 定位：全项目唯一的「对外发通知」入口。面板此前所有异常都是静默的（证书到期、
 * 备份失败、节点失联都无人知道），本模块提供三类通道并把结果结构化返回：
 *   smtp      —— 原生 socket 实现的 SMTP 客户端（STARTTLS / 隐式 TLS / AUTH LOGIN）
 *   telegram  —— Bot API sendMessage（可改 API base 走反代）
 *   webhook   —— 自定义 HTTP 回调（JSON / form，状态码 2xx/3xx 视为成功）
 *
 * 为什么自己实现 SMTP 而不引入 PHPMailer：kangle 容器内是 vendored Remi PHP 7.4
 * （kangle/vendor/php74.tar.gz，实测 `php74 -m` 有 curl/openssl/sockets/hash，
 * 没有任何 composer 自动加载目录），装第三方库要动镜像层，代价远大于一个
 * RFC 5321 最小客户端。SMTP 部分只走 EHLO/STARTTLS/AUTH LOGIN/MAIL/RCPT/DATA，
 * 不实现扩展协议，够用且可控。
 *
 * 其它单元如何调用（集成步之后可直接复用）：
 *   apicall('notify', 'send', array('标题', '正文', array('type'=>'telegram')))
 *   apicall('notify', 'send', array('标题', '正文'))          // 全部启用渠道
 * 返回统一为 array('success'=>bool,'count'=>int,'results'=>array(...))，
 * 每个 result 见 send() 注释。发送失败绝不抛异常、绝不 exit（旁路设施不阻断业务）。
 *
 * 安全边界：
 *   - 渠道 URL / SMTP 地址由管理员配置，属可信输入；仍强制 http/https 白名单，
 *     挡掉 file:// gopher:// 等被误填成 SSRF 跳板协议（host.docker.internal
 *     这类私网目标是本功能的正常用法，不做私网封禁）。
 *   - 每个渠道独立超时（默认 10s，可配 1~30s），避免一次广播把请求线程拖死。
 *   - config 里的密码/token 原样存 SQLite（与 manynode.skey 同一口径），
 *     列表页与表单一律掩码显示，保存时空值表示「沿用原值」。
 */
class NotifyApi extends API
{
	const TYPES = array('smtp', 'telegram', 'webhook');

	/** 默认单次发送超时（秒） */
	const DEFAULT_TIMEOUT = 10;

	/**
	 * 发送一条通知
	 *
	 * @param string $title 标题（渠道各自的标题/首行/JSON title）
	 * @param string $text  正文（纯文本；webhook/telegram 会按配置带上 HTML/Markdown）
	 * @param array  $opts  array(
	 *                          'channel_id' => int|null 只发某个渠道,
	 *                          'type'       => string   只发某类渠道,
	 *                          'skip_disabled' => bool  默认 true,
	 *                          'html'       => string   富文本正文（可选）,
	 *                      )
	 * @return array array('success'=>bool,'count'=>int,'sent'=>int,'failed'=>int,
	 *                      'results'=>array(array('id','name','type','status',
	 *                      'success','error','http_code')))
	 */
	public function send($title, $text, $opts = array())
	{
		$ret = array('success' => false, 'count' => 0, 'sent' => 0, 'failed' => 0, 'results' => array());

		$title = is_scalar($title) ? trim(strval($title)) : '';
		$text = is_scalar($text) ? strval($text) : '';

		if ($title === '' && $text === '') {
			$ret['error'] = 'empty message';
			return $ret;
		}

		if ($title === '') {
			$title = 'EasyPanel 通知';
		}

		if ($text === '') {
			$text = $title;
		}

		// 表由 upgrade.sql 增量创建；调用方（证书/备份/健康探测等单元）可能跑在
		// 升级脚本尚未重放的环境里，这里兜一次底，保证只是「发不出去」而不是报错。
		daocall('notifychannel', 'ensureTable', array());

		// 标题里不允许换行（SMTP 头注入 / Telegram 首行错乱）
		$title = str_replace(array("\r", "\n"), ' ', $title);
		$html = isset($opts['html']) && is_scalar($opts['html']) ? strval($opts['html']) : '';

		$channels = $this->pickChannels($opts);

		if (empty($channels)) {
			$ret['error'] = 'no channel matched';
			return $ret;
		}

		$ret['count'] = count($channels);

		foreach ($channels as $channel) {
			$one = $this->sendChannel($channel, $title, $text, $html);
			$ret['results'][] = $one;

			if (!empty($one['success'])) {
				++$ret['sent'];
			}
			else {
				++$ret['failed'];
			}
		}

		$ret['success'] = $ret['sent'] > 0;
		return $ret;
	}

	/**
	 * 测试某个渠道（验收动作：?c=notify&a=testSend&id=1）
	 *
	 * 与被测渠道真实发送完全同一条代码路径，只是正文换成一条自述消息，
	 * 便于「点了就必定在目标侧留下痕迹」的可验证性。
	 *
	 * @param int $id
	 * @return array 与 sendChannel 同结构，另带 'msg' 人类可读结论
	 */
	public function testSend($id)
	{
		$id = intval($id);
		$channel = daocall('notifychannel', 'get', array($id));

		if (!$channel) {
			return $this->failResult($id, '', '', '渠道不存在（id=' . $id . '）');
		}

		$title = 'EasyPanel 通知渠道测试';
		$text = "这是一条来自 EasyPanel 管理后台的测试消息。\n"
			. '渠道：' . $channel['name'] . '（' . $channel['type'] . "）\n"
			. '时间：' . date('Y-m-d H:i:s') . "\n"
			. '节点：' . $this->localName();

		// force=true：testSend 是管理员点名要验的单个渠道，不受「停用」限制。
		// 常见用法是先把渠道存成停用、试通了再启用；若这里拒绝发送，就等于
		// 强制「启用→测试→再停用」的来回切换。自动广播路径仍然看 enabled。
		$one = $this->sendChannel($channel, $title, $text, $this->testHtml($channel, $text), true);
		$one['msg'] = $one['success']
			? '发送成功：' . $one['type'] . ' 渠道「' . $one['name'] . '」已接收'
				. (intval($channel['enabled']) === 1 ? '。' : '（该渠道当前为停用状态，自动告警不会走它）')
			: '发送失败：' . ($one['error'] !== '' ? $one['error'] : '未知原因');

		return $one;
	}

	/**
	 * 校验并规范化渠道配置（保存前调用，也供 testSend 前的静默检查）
	 *
	 * @param string $type
	 * @param array  $cfg
	 * @return array array('ok'=>bool,'msg'=>string,'cfg'=>array 规范化后的配置)
	 */
	public function validate($type, $cfg)
	{
		if (!in_array($type, self::TYPES, true)) {
			return array('ok' => false, 'msg' => '不支持的渠道类型：' . $type, 'cfg' => array());
		}

		$cfg = is_array($cfg) ? $cfg : array();
		$out = array('ok' => false, 'msg' => '', 'cfg' => array());

		switch ($type) {
		case 'smtp':
			$host = trim(isset($cfg['host']) ? strval($cfg['host']) : '');
			$port = intval(isset($cfg['port']) ? $cfg['port'] : 0);
			$secure = strtolower(trim(isset($cfg['secure']) ? strval($cfg['secure']) : 'auto'));

			if ($host === '') {
				$out['msg'] = 'SMTP 服务器地址不能为空';
				return $out;
			}

			if (!preg_match('/^[A-Za-z0-9._\-:]+$/', $host)) {
				$out['msg'] = 'SMTP 服务器地址格式不正确';
				return $out;
			}

			if ($port <= 0 || $port > 65535) {
				$port = ($secure === 'ssl') ? 465 : 587;
			}

			if (!in_array($secure, array('auto', 'ssl', 'tls', 'none'), true)) {
				$secure = 'auto';
			}

			$from = trim(isset($cfg['from']) ? strval($cfg['from']) : '');

			if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
				$out['msg'] = '发件人地址（From）不是合法邮箱';
				return $out;
			}

			$to = $this->parseEmails(isset($cfg['to']) ? strval($cfg['to']) : '');

			if (empty($to)) {
				$out['msg'] = '收件人（To）为空或全部不是合法邮箱';
				return $out;
			}

			$user = strval(isset($cfg['user']) ? $cfg['user'] : '');

			if ($user !== '' && strval(isset($cfg['pass']) ? $cfg['pass'] : '') === '') {
				$out['msg'] = 'SMTP 已填写账号但密码为空';
				return $out;
			}

			$out['cfg'] = array(
				'host'       => $host,
				'port'       => $port,
				'secure'     => $secure,
				'user'       => $user,
				'pass'       => strval(isset($cfg['pass']) ? $cfg['pass'] : ''),
				'from'       => $from,
				'from_name'  => trim(strval(isset($cfg['from_name']) ? $cfg['from_name'] : '')),
				'to'         => implode(',', $to),
				'timeout'    => $this->normTimeout(isset($cfg['timeout']) ? $cfg['timeout'] : 0),
				'charset'    => 'UTF-8',
			);

			$out['ok'] = true;
			return $out;

		case 'telegram':
			$token = trim(strval(isset($cfg['token']) ? $cfg['token'] : ''));
			$chat_id = trim(strval(isset($cfg['chat_id']) ? $cfg['chat_id'] : ''));
			$base = trim(strval(isset($cfg['api_base']) ? $cfg['api_base'] : ''));

			// token 形如 123456:ABC-DEF...。这里只挡「能破坏 URL 结构」的字符
			// （斜杠/问号/井号/&/空白/控制符），不校验业务格式：填错时让 Telegram
			// 自己回 401/404，管理员看到的是真实服务端原因，而不是本地正则挡下的猜测。
			if ($token === '') {
				$out['msg'] = 'Telegram Bot Token 不能为空（@BotFather 获取，形如 123456:ABC-DEF...）';
				return $out;
			}

			if (!preg_match('/^[!-~]{5,190}$/', $token) || preg_match('~[/?&#\s]~', $token)) {
				$out['msg'] = 'Telegram Bot Token 含非法字符（不能包含斜杠、问号、井号、& 与空白）';
				return $out;
			}

			// chat_id 可以是数字（私聊/群组/超级群组负数）或 @channelname
			if ($chat_id === '' || !preg_match('/^[@\-_A-Za-z0-9]{1,64}$/', $chat_id)) {
				$out['msg'] = 'Telegram chat_id 不合法：应为数字（群组/超级群组可为负数）或 @频道名';
				return $out;
			}

			if ($base !== '') {
				$checked = $this->checkUrl($base);

				if (!$checked['ok']) {
					$out['msg'] = 'Telegram API Base ' . $checked['msg'];
					return $out;
				}
			}

			$parse_mode = strtolower(trim(strval(isset($cfg['parse_mode']) ? $cfg['parse_mode'] : 'html')));

			if (!in_array($parse_mode, array('html', 'markdown', 'plain'), true)) {
				$parse_mode = 'plain';
			}

			$thread = intval(isset($cfg['thread_id']) ? $cfg['thread_id'] : 0);

			$out['cfg'] = array(
				'token'      => $token,
				'chat_id'    => $chat_id,
				'api_base'   => $base === '' ? 'https://api.telegram.org' : rtrim($base, '/'),
				'parse_mode' => $parse_mode,
				'thread_id'  => $thread > 0 ? $thread : 0,
				'timeout'    => $this->normTimeout(isset($cfg['timeout']) ? $cfg['timeout'] : 0),
			);

			$out['ok'] = true;
			return $out;

		case 'webhook':
			$url = trim(strval(isset($cfg['url']) ? $cfg['url'] : ''));
			$checked = $this->checkUrl($url);

			if (!$checked['ok']) {
				$out['msg'] = 'Webhook 地址 ' . $checked['msg'];
				return $out;
			}

			$method = strtoupper(trim(strval(isset($cfg['method']) ? $cfg['method'] : 'POST')));

			if (!in_array($method, array('POST', 'GET', 'PUT'), true)) {
				$method = 'POST';
			}

			$body_type = strtolower(trim(strval(isset($cfg['body_type']) ? $cfg['body_type'] : 'json')));

			if (!in_array($body_type, array('json', 'form', 'text'), true)) {
				$body_type = 'json';
			}

			$template = strval(isset($cfg['body_template']) ? $cfg['body_template'] : '');

			if ($template !== '') {
				// 模板里必须至少有一个占位符，否则每次发的都是同一坨常量
				if (strpos($template, '{title}') === false && strpos($template, '{content}') === false) {
					$out['msg'] = 'Webhook 自定义正文需包含 {title} 或 {content} 占位符';
					return $out;
				}
			}

			$out['cfg'] = array(
				'url'           => $checked['url'],
				'method'        => $method,
				'body_type'     => $body_type,
				'body_template' => $template,
				'headers'       => $this->parseHeaders(isset($cfg['headers']) ? $cfg['headers'] : ''),
				'sign_key'      => trim(strval(isset($cfg['sign_key']) ? $cfg['sign_key'] : '')),
				'timeout'       => $this->normTimeout(isset($cfg['timeout']) ? $cfg['timeout'] : 0),
			);

			$out['ok'] = true;
			return $out;
		}

		$out['msg'] = '未知渠道类型';
		return $out;
	}

	/**
	 * 渠道配置的敏感字段名（列表/表单掩码 + 「留空沿用原值」）
	 */
	public function secretKeys($type)
	{
		switch ($type) {
		case 'smtp':
			return array('pass');
		case 'telegram':
			return array('token');
		case 'webhook':
			return array('sign_key');
		}

		return array();
	}

	/* --------------------------------------------------------------------
	 *  内部实现
	 * ------------------------------------------------------------------ */

	/**
	 * 按 opts 选出要发送的渠道行
	 */
	private function pickChannels($opts)
	{
		$channel_id = isset($opts['channel_id']) ? intval($opts['channel_id']) : 0;
		$type = isset($opts['type']) && is_scalar($opts['type']) ? strtolower(trim(strval($opts['type']))) : '';

		// 未知 type 视作「不按类型过滤」，避免调用方拼错类型时静默一条都不发
		if ($type !== '' && !in_array($type, self::TYPES, true)) {
			trigger_error('notify: 未知渠道类型 ' . $type . '，按全部类型处理', E_USER_NOTICE);
			$type = '';
		}

		$skip_disabled = !isset($opts['skip_disabled']) || !empty($opts['skip_disabled']);

		if ($channel_id > 0) {
			$one = daocall('notifychannel', 'get', array($channel_id));

			if (!$one) {
				return array();
			}

			if ($skip_disabled && intval($one['enabled']) !== 1) {
				return array();
			}

			return array($one);
		}

		if ($skip_disabled) {
			$list = daocall('notifychannel', 'getEnabled', array($type !== '' ? $type : null));
		}
		else {
			// skip_disabled=false：全部渠道都要看，但 type 过滤不能丢，
			// 否则「只发 webhook」会把 smtp/telegram 一起发出去。
			$list = daocall('notifychannel', 'get', array());

			if ($type !== '') {
				$filtered = array();

				foreach ((array) $list as $row) {
					if (strval($row['type']) === $type) {
						$filtered[] = $row;
					}
				}

				$list = $filtered;
			}
		}

		return is_array($list) ? $list : array();
	}

	/**
	 * 向单个渠道发送并落库结果（绝不抛异常）
	 *
	 * @param bool $force 忽略 enabled 标记（仅供 testSend 点名重发；自动广播永远不传）
	 * @return array array('id','name','type','status','success','error','http_code')
	 */
	private function sendChannel($channel, $title, $text, $html = '', $force = false)
	{
		$id = intval($channel['id']);
		$name = strval($channel['name']);
		$type = strval($channel['type']);
		$cfg = $this->decodeConfig($channel);

		$result = array(
			'id'        => $id,
			'name'      => $name,
			'type'      => $type,
			'status'    => 'failed',
			'success'   => false,
			'error'     => '',
			'http_code' => 0,
		);

		if (!in_array($type, self::TYPES, true)) {
			$result['error'] = '不支持的渠道类型：' . $type;
			$this->mark($id, $result);
			return $result;
		}

		if (!$force && intval(isset($channel['enabled']) ? $channel['enabled'] : 1) !== 1) {
			// 广播路径跳过停用渠道；不写 last_*（没有真的发送，不能伪装成发送结果）
			$result['status'] = 'skipped';
			$result['error'] = '渠道已停用';
			return $result;
		}

		$checked = $this->validate($type, $cfg);

		if (!$checked['ok']) {
			$result['error'] = $checked['msg'];
			$this->mark($id, $result);
			return $result;
		}

		$cfg = $checked['cfg'];

		try {
			switch ($type) {
			case 'smtp':
				$one = $this->sendSmtp($cfg, $title, $html !== '' ? $html : $text, $html !== '');
				break;
			case 'telegram':
				$one = $this->sendTelegram($cfg, $title, $text);
				break;
			case 'webhook':
				$one = $this->sendWebhook($cfg, $title, $text);
				break;
			default:
				$one = array('success' => false, 'error' => 'unreachable', 'http_code' => 0);
			}
		}
		catch (Exception $e) {
			$one = array('success' => false, 'error' => '发送异常：' . $e->getMessage(), 'http_code' => 0);
		}
		catch (Throwable $e) {
			// PHP 7 的 Error 不属于 Exception，兜一层保证「通知绝不炸业务」
			$one = array('success' => false, 'error' => '发送异常：' . $e->getMessage(), 'http_code' => 0);
		}

		$result['success'] = !empty($one['success']);
		$result['status'] = $result['success'] ? 'sent' : 'failed';
		// 异常分支的 getMessage() 可能带任意字节，统一再洗一遍（fail() 路径是幂等的）
		$result['error'] = $this->utf8Safe(isset($one['error']) ? $one['error'] : '');
		$result['http_code'] = intval(isset($one['http_code']) ? $one['http_code'] : 0);
		$this->mark($id, $result);
		return $result;
	}

	private function mark($id, $result)
	{
		if ($result['status'] === 'skipped') {
			return;
		}

		$status = $result['success'] ? 1 : 2;
		$error = $result['success'] ? '' : $result['error'];
		daocall('notifychannel', 'markResult', array($id, $status, $error));
	}

	/**
	 * SMTP：原生 socket 最小客户端
	 *
	 * @param array  $cfg   validate() 规范化后的配置
	 * @param string $title 主题
	 * @param string $body  正文
	 * @param bool   $is_html
	 * @return array array('success','error','http_code'=>0)
	 */
	private function sendSmtp($cfg, $title, $body, $is_html = false)
	{
		$timeout = intval($cfg['timeout']) > 0 ? intval($cfg['timeout']) : self::DEFAULT_TIMEOUT;
		$errno = 0;
		$errstr = '';

		if ($cfg['secure'] === 'ssl') {
			$remote = 'ssl://' . $cfg['host'] . ':' . $cfg['port'];
			$context = stream_context_create(array('ssl' => array(
				// 主机名校验对自签内网邮件服务器不实用，与项目既有 HTTP 客户端口径一致
				'verify_peer'      => false,
				'verify_peer_name' => false,
				'allow_self_signed' => true,
			)));
		}
		else {
			$remote = 'tcp://' . $cfg['host'] . ':' . $cfg['port'];
			$context = stream_context_create();
		}

		$fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

		if (!$fp) {
			return $this->fail('无法连接 SMTP 服务器 ' . $remote . '：' . ($errstr !== '' ? $errstr : 'errno=' . $errno));
		}

		stream_set_timeout($fp, $timeout);

		$host = $this->smtpHelloName();
		$expect = $this->smtpRead($fp, array(220));

		if ($expect === false) {
			fclose($fp);
			return $this->fail('SMTP 连接后未收到 220 欢迎语' . ($this->smtpLastError($fp) !== '' ? '：' . $this->smtpLastError($fp) : ''));
		}

		// EHLO 失败退回 HELO（少数老服务）
		$features = $this->smtpCommand($fp, 'EHLO ' . $host, array(250));

		if ($features === false) {
			if ($this->smtpCommand($fp, 'HELO ' . $host, array(250)) === false) {
				fclose($fp);
				return $this->fail('SMTP EHLO/HELO 被服务器拒绝');
			}

			$features = array();
		}

		$caps = $this->smtpCapabilities($features);

		// STARTTLS 策略要尊重管理员的显式选择：
		//   ssl  = 隐式 TLS，连接本身就是加密的，不做 STARTTLS
		//   tls  = 必须升级成功，否则失败
		//   auto = 服务器提供就升级（机会性）
		//   none = 明确不加密，即使服务器提供也不升级
		if (in_array($cfg['secure'], array('auto', 'tls'), true)) {
			if (!empty($caps['raw']['STARTTLS'])) {
				if ($this->smtpCommand($fp, 'STARTTLS', array(220)) === false) {
					fclose($fp);
					return $this->fail('STARTTLS 协商失败');
				}

				// PHP 7.4 起 ANY_CLIENT = 让 OpenSSL 自选最高 TLS 版本；
				// TLS_CLIENT 在老版本里会被限制成 TLS1.0，GMail/企微之流会拒。
				$crypto = defined('STREAM_CRYPTO_METHOD_ANY_CLIENT')
					? STREAM_CRYPTO_METHOD_ANY_CLIENT
					: STREAM_CRYPTO_METHOD_TLS_CLIENT;
				$ok = @stream_socket_enable_crypto($fp, true, $crypto);

				if (!$ok) {
					fclose($fp);
					return $this->fail('STARTTLS 加密通道建立失败（检查容器 openssl / CA 配置）');
				}

				$features = $this->smtpCommand($fp, 'EHLO ' . $host, array(250));

				if ($features === false) {
					fclose($fp);
					return $this->fail('STARTTLS 后 EHLO 重协商失败');
				}

				$caps = $this->smtpCapabilities($features);
			}
			elseif ($cfg['secure'] === 'tls') {
				fclose($fp);
				return $this->fail('SMTP 服务器不支持 STARTTLS，但配置要求强制 TLS');
			}
		}

		if ($cfg['user'] !== '') {
			$auth = $this->smtpAuth($fp, $cfg, $caps);

			if ($auth !== true) {
				fclose($fp);
				return $this->fail($auth);
			}
		}

		$from = $cfg['from'];

		if ($this->smtpCommand($fp, 'MAIL FROM:<' . $from . '>', array(250)) === false) {
			fclose($fp);
			return $this->fail('SMTP MAIL FROM 被拒绝');
		}

		$accepted = 0;

		foreach (explode(',', $cfg['to']) as $rcpt) {
			if ($rcpt === '') {
				continue;
			}

			if ($this->smtpCommand($fp, 'RCPT TO:<' . $rcpt . '>', array(250, 251)) === false) {
				continue;
			}

			++$accepted;
		}

		if ($accepted <= 0) {
			fclose($fp);
			return $this->fail('SMTP 所有收件人均被拒绝');
		}

		if ($this->smtpCommand($fp, 'DATA', array(354)) === false) {
			fclose($fp);
			return $this->fail('SMTP DATA 阶段被拒绝');
		}

		$message = $this->buildMime($cfg, $title, $body, $is_html);
		// 点填充（dot-stuffing）+ CRLF 统一
		$message = $this->smtpDotStuff($message);

		if (@fwrite($fp, $message . "\r\n.\r\n") === false) {
			fclose($fp);
			return $this->fail('SMTP 写入邮件正文失败');
		}

		if (@fflush($fp) === false) {
			fclose($fp);
			return $this->fail('SMTP 刷新输出缓冲失败');
		}

		$end = $this->smtpRead($fp, array(250));

		if ($end === false) {
			fclose($fp);
			return $this->fail('SMTP 服务器未接受邮件（DATA 后无 250）');
		}

		$this->smtpCommand($fp, 'QUIT', array(221));
		fclose($fp);

		return array('success' => true, 'error' => '', 'http_code' => 0);
	}

	/**
	 * 组装邮件（含头部编码，主题/姓名走 RFC 2047，正文 quoted-printable）
	 */
	private function buildMime($cfg, $title, $body, $is_html)
	{
		$from_name = $cfg['from_name'] !== '' ? $cfg['from_name'] : 'EasyPanel';
		$lines = array();
		$lines[] = 'Date: ' . @date('r');
		$lines[] = 'From: ' . $this->mimeAddress($from_name, $cfg['from']);
		$lines[] = 'To: ' . $this->mimeAddress('', $cfg['to']);
		$lines[] = 'Subject: ' . $this->mimeHeaderEncode($title);
		$lines[] = 'Message-ID: <' . $this->messageId() . '>';
		$lines[] = 'MIME-Version: 1.0';
		$lines[] = 'X-Mailer: EasyPanel/' . (defined('EASYPANEL_VERSION') ? EASYPANEL_VERSION : 'x');
		$lines[] = 'Content-Type: text/' . ($is_html ? 'html' : 'plain') . '; charset="' . $cfg['charset'] . '"';
		$lines[] = 'Content-Transfer-Encoding: quoted-printable';
		$lines[] = '';
		$lines[] = $this->quotedPrintable($this->normalizeText($body));

		return implode("\r\n", $lines);
	}

	private function mimeAddress($name, $addr)
	{
		$name = trim(preg_replace('/[\r\n]/', ' ', $name));
		$addr = trim(preg_replace('/[\r\n]/', ' ', $addr));

		if ($name === '') {
			return $addr;
		}

		// 非 ASCII 姓名走 RFC 2047；ASCII 姓名加引号即可
		if (preg_match('/^[ -~]+$/', $name)) {
			return '"' . str_replace('"', '\\"', $name) . '" <' . $addr . '>';
		}

		return $this->mimeHeaderEncode($name) . ' <' . $addr . '>';
	}

	private function mimeHeaderEncode($s)
	{
		$s = preg_replace('/[\r\n]/', ' ', $s);

		// 纯 ASCII 不必编码，保持头部最短、兼容性最好
		if (preg_match('/^[ -~]+$/', $s)) {
			return str_replace('\\', '\\\\', $s);
		}

		return '=?UTF-8?B?' . base64_encode($s) . '?=';
	}

	private function messageId()
	{
		$host = $this->smtpHelloName();
		$host = preg_replace('/[^A-Za-z0-9.\-]/', 'x', $host);

		return md5(uniqid(strval(rand()), true)) . '.ep@' . $host;
	}

	private function normalizeText($text)
	{
		$text = str_replace(array("\r\n", "\r"), "\n", $text);

		return $text;
	}

	private function quotedPrintable($text)
	{
		$out = '';
		$len = strlen($text);
		$line = '';

		for ($i = 0; $i < $len; $i++) {
			$ch = $text[$i];

			if ($ch === "\n") {
				$out .= rtrim($line, ' ') . "\r\n";
				$line = '';
				continue;
			}

			$code = ord($ch);
			$enc = ($code == 32 || ($code >= 33 && $code <= 126 && $code != 61)) ? $ch : sprintf('=%02X', $code);

			if (strlen($line) + strlen($enc) > 74) {
				$out .= rtrim($line, ' ') . "=\r\n";
				$line = '';
			}

			$line .= $enc;
		}

		$out .= rtrim($line, ' ');

		return $out;
	}

	/**
	 * dot-stuffing：正文中单独成的那行按 RFC 5321 要前置一个点
	 */
	private function smtpDotStuff($msg)
	{
		$msg = str_replace(array("\r\n", "\r"), "\n", $msg);
		$lines = explode("\n", $msg);

		foreach ($lines as $k => $line) {
			if (strlen($line) > 0 && $line[0] === '.') {
				$lines[$k] = '.' . $line;
			}
		}

		return implode("\r\n", $lines);
	}

	private function smtpHelloName()
	{
		$name = gethostname();

		if (!$name || !preg_match('/^[A-Za-z0-9.\-]+$/', $name)) {
			$name = 'easypanel.local';
		}

		return $name;
	}

	/**
	 * 读取一条 SMTP 应答（可能是多行），返回 array('code','text','lines')；异常返回 false
	 *
	 * 多行判定按 RFC 5321：状态码后紧跟 '-' 表示还有后续行，紧跟空格（或该行只有
	 * 状态码）即为末行。不能只判断「第 4 个字符是空格」，否则遇到裸 `220\r\n`
	 * 这种少数服务器写法会一直读到超时。
	 */
	private function smtpReadLine($fp)
	{
		$data = '';

		while (!feof($fp)) {
			$chunk = fgets($fp, 2048);

			if ($chunk === false) {
				break;
			}

			$data .= $chunk;
			$line = rtrim($chunk, "\r\n");

			// 末行：长度不足 4（裸状态码）或第 4 个字符不是 '-'
			if (strlen($line) < 4 || $line[3] !== '-') {
				break;
			}
		}

		$data = trim($data);

		if ($data === '' || !preg_match('/^([0-9]{3})/', $data, $m)) {
			return false;
		}

		return array('code' => intval($m[1]), 'text' => $data, 'lines' => explode("\r\n", $data));
	}

	private function smtpRead($fp, $expect = array())
	{
		$resp = $this->smtpReadLine($fp);

		if ($resp === false) {
			return false;
		}

		if (!empty($expect) && !in_array($resp['code'], $expect, true)) {
			return false;
		}

		return $resp;
	}

	private function smtpCommand($fp, $cmd, $expect = array())
	{
		if (@fwrite($fp, $cmd . "\r\n") === false) {
			return false;
		}

		return $this->smtpRead($fp, $expect);
	}

	private function smtpExpect($resp, $code)
	{
		return is_array($resp) && isset($resp['code']) && $resp['code'] == $code;
	}

	private function smtpErrorText($resp)
	{
		if (is_array($resp) && isset($resp['text'])) {
			return $resp['text'];
		}

		return '服务器应答异常';
	}

	private function smtpLastError($fp)
	{
		$meta = @stream_get_meta_data($fp);

		if (is_array($meta) && !empty($meta['timed_out'])) {
			return '读取超时';
		}

		return '';
	}

	/**
	 * 把 EHLO 应答折成能力表
	 *
	 * @return array array('raw'=>array('AUTH'=>'AUTH LOGIN PLAIN',...), 'auth'=>array('LOGIN','PLAIN',...))
	 *         键统一大写、去掉参数，便于 isset() 判定；raw 保留原文供 AUTH 取机制。
	 */
	private function smtpCapabilities($resp)
	{
		$caps = array('raw' => array(), 'auth' => array());

		if (!is_array($resp) || empty($resp['lines'])) {
			return $caps;
		}

		foreach ($resp['lines'] as $line) {
			$line = strtoupper(trim(preg_replace('/^[0-9]{3}[- ]?/', '', trim($line))));

			if ($line === '') {
				continue;
			}

			$parts = preg_split('/\s+/', $line, 2);
			$name = $parts[0];
			$caps['raw'][$name] = $line;

			if ($name === 'AUTH' && isset($parts[1])) {
				foreach (preg_split('/\s+/', trim($parts[1])) as $mech) {
					if ($mech !== '') {
						$caps['auth'][strtoupper($mech)] = true;
					}
				}
			}
		}

		return $caps;
	}

	/**
	 * SMTP 认证：按服务器声明的机制依次尝试
	 *
	 * @return true|string 成功返回 true；失败返回人类可读原因
	 */
	private function smtpAuth($fp, $cfg, $caps)
	{
		$auths = isset($caps['auth']) && is_array($caps['auth']) ? $caps['auth'] : array();

		// 顺序偏好：LOGIN 最普遍；PLAIN 次之；CRAM-MD5 需要 hash_hmac（容器内实测可用）
		$order = array('LOGIN', 'PLAIN');
		$tried = array();

		foreach ($order as $mech) {
			if (!empty($auths[$mech])) {
				$tried[] = $mech;
			}
		}

		// 服务器没声明 AUTH 但填了账号：直接按 LOGIN 试一次（少数服务省略能力声明）
		if (empty($tried) && empty($auths)) {
			$tried[] = 'LOGIN';
		}
		elseif (empty($tried)) {
			return '服务器不支持 LOGIN/PLAIN 认证机制（已声明：' . implode(' ', array_keys($auths)) . '）';
		}

		$lastErr = '认证失败';

		foreach ($tried as $mech) {
			$r = $mech === 'PLAIN'
				? $this->smtpAuthPlain($fp, $cfg)
				: $this->smtpAuthLogin($fp, $cfg);

			if ($r === true) {
				return true;
			}

			$lastErr = $r;
		}

		return $lastErr;
	}

	private function smtpAuthLogin($fp, $cfg)
	{
		// 只接受 334（要继续发凭据）/ 235（已认证）。504 等拒绝码必须当场收手，
		// 绝不能把账号/密码继续喂给一个已经回绝 AUTH 的服务器。
		$resp = $this->smtpCommand($fp, 'AUTH LOGIN', array(334, 235));

		if ($resp === false) {
			return 'AUTH LOGIN 未被服务器接受';
		}

		if ($this->smtpExpect($resp, 235)) {
			return true;
		}

		$r1 = $this->smtpCommand($fp, base64_encode($cfg['user']), array(334, 235));

		if ($r1 === false) {
			return '提交 SMTP 账号未被接受（连接中断或应答异常）';
		}

		if ($this->smtpExpect($r1, 235)) {
			return true;
		}

		$r2 = $this->smtpCommand($fp, base64_encode($cfg['pass']), array(235, 535, 504));

		if ($r2 === false) {
			return '提交 SMTP 密码时连接中断';
		}

		if ($this->smtpExpect($r2, 235)) {
			return true;
		}

		return 'SMTP 认证失败（LOGIN）：' . $this->smtpErrorText($r2);
	}

	private function smtpAuthPlain($fp, $cfg)
	{
		// RFC 4616: base64("\0authzid\0authcid\0passwd")
		$resp = $this->smtpCommand($fp, 'AUTH PLAIN ' . base64_encode("\0" . $cfg['user'] . "\0" . $cfg['pass']), array(235, 535, 504));

		if ($resp === false) {
			return 'AUTH PLAIN 未被服务器接受';
		}

		if ($this->smtpExpect($resp, 235)) {
			return true;
		}

		return 'SMTP 认证失败（PLAIN）：' . $this->smtpErrorText($resp);
	}

	/**
	 * Telegram sendMessage
	 *
	 * 假 token 时 Telegram 返回 HTTP 401 + {"ok":false,"error_code":401,
	 * "description":"Unauthorized"}，本函数把它转成 'Telegram 401: Unauthorized'
	 * 的结构化失败，供验收断言。
	 */
	private function sendTelegram($cfg, $title, $text)
	{
		$base = rtrim($cfg['api_base'], '/');
		$url = $base . '/bot' . $cfg['token'] . '/sendMessage';

		// Telegram 的 HTML/Markdown 会吞掉未转义的尖括号与引号，正文一律转义
		if ($cfg['parse_mode'] === 'html') {
			$message = '<b>' . $this->tgEscape($title, 'html') . '</b>' . "\n" . $this->tgEscape($text, 'html');
		}
		elseif ($cfg['parse_mode'] === 'markdown') {
			$message = '*' . $this->tgEscape($title, 'markdown') . '*' . "\n" . $this->tgEscape($text, 'markdown');
		}
		else {
			$message = $title . "\n" . $text;
		}

		$fields = array(
			'chat_id' => $cfg['chat_id'],
			'text'    => $message,
		);

		if ($cfg['parse_mode'] === 'html') {
			$fields['parse_mode'] = 'HTML';
			$fields['disable_web_page_preview'] = '1';
		}
		elseif ($cfg['parse_mode'] === 'markdown') {
			$fields['parse_mode'] = 'Markdown';
		}

		if ($cfg['thread_id'] > 0) {
			$fields['message_thread_id'] = strval($cfg['thread_id']);
		}

		$http = $this->httpRequest($url, 'POST', http_build_query($fields), array(
			'Content-Type: application/x-www-form-urlencoded',
		), intval($cfg['timeout']));

		if (!$http['ok']) {
			return $this->fail('Telegram 请求失败：' . $http['error'], $http['code']);
		}

		$json = json_decode($http['body'], true);

		if (is_array($json) && !empty($json['ok'])) {
			return array('success' => true, 'error' => '', 'http_code' => $http['code']);
		}

		// 结构化错误：Telegram 用 error_code/description 表达 401/400/429 等
		if (is_array($json)) {
			$code = isset($json['error_code']) ? intval($json['error_code']) : $http['code'];
			$desc = isset($json['description']) ? strval($json['description']) : '';

			return $this->fail('Telegram ' . $code . ($desc !== '' ? ': ' . $desc : ': 接口返回 ok=false'), $http['code']);
		}

		return $this->fail('Telegram 返回无法解析（HTTP ' . $http['code'] . '）：'
			. substr($this->oneLine($http['body']), 0, 180), $http['code']);
	}

	private function tgEscape($s, $mode)
	{
		$s = $this->normalizeText($s);

		if ($mode === 'html') {
			return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
		}

		$chars = array('_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '{', '}', '.', '!', '\\');
		$out = '';

		for ($i = 0, $len = strlen($s); $i < $len; $i++) {
			$ch = $s[$i];
			// 换行原样保留：http_build_query 会把它编码成 %0A，这里再塞字面
			// %0A 会被二次编码成 %250A，Telegram 收到的就是裸的 "%0A" 文本
			$out .= in_array($ch, $chars, true) ? '\\' . $ch : $ch;
		}

		return $out;
	}

	/**
	 * 自定义 Webhook
	 *
	 * 判定口径（验收依据）：HTTP 2xx/3xx 即成功。nginxdemos/hello 对 POST / 返回
	 * 200 并在容器日志留下 `127.0.0.1 [timestamp] "POST / HTTP/1.1" 200`，
	 * 因此「成功 JSON + 靶容器日志命中」构成交付的确据。
	 */
	private function sendWebhook($cfg, $title, $text)
	{
		$url = $cfg['url'];
		$method = $cfg['method'];
		$headers = array('User-Agent: EasyPanel-Notify/' . (defined('EASYPANEL_VERSION') ? EASYPANEL_VERSION : 'x'));

		foreach ($cfg['headers'] as $k => $v) {
			$headers[] = $k . ': ' . $v;
		}

		$payload = $this->buildWebhookBody($cfg, $title, $text);

		if ($method === 'GET') {
			// GET 语义：正文改为查询串（只带 title/content，避免超长 URL）
			$connector = strpos($url, '?') === false ? '?' : '&';
			$url .= $connector . http_build_query(array('title' => $title, 'content' => $text));
			$payload = '';
			$headers[] = 'Content-Length: 0';
		}
		else {
			if ($cfg['body_type'] === 'json') {
				$headers[] = 'Content-Type: application/json; charset=utf-8';
			}
			elseif ($cfg['body_type'] === 'form') {
				$headers[] = 'Content-Type: application/x-www-form-urlencoded; charset=utf-8';
			}
			else {
				$headers[] = 'Content-Type: text/plain; charset=utf-8';
			}

			$headers[] = 'Content-Length: ' . strlen($payload);
		}

		if ($cfg['sign_key'] !== '') {
			// 可选签名：HMAC-SHA256(body)，接收端凭 X-Ep-Signature 校验来源
			$sig = hash_hmac('sha256', $payload, $cfg['sign_key']);
			$headers[] = 'X-Ep-Signature: sha256=' . $sig;
			$headers[] = 'X-Ep-Timestamp: ' . strval(time());
		}

		$http = $this->httpRequest($url, $method, $payload, $headers, intval($cfg['timeout']));

		if (!$http['ok']) {
			return $this->fail('Webhook 请求失败：' . $http['error'], $http['code']);
		}

		if ($http['code'] >= 200 && $http['code'] < 400) {
			// 2xx/3xx 成功；若响应体是常见机器人平台的 JSON 错误码，则升级为失败
			$biz = $this->inspectBizError($http['body']);

			if ($biz !== '') {
				return $this->fail('Webhook 返回业务错误：' . $biz, $http['code']);
			}

			return array('success' => true, 'error' => '', 'http_code' => $http['code']);
		}

		return $this->fail('Webhook 返回 HTTP ' . $http['code'] . '：'
			. substr($this->oneLine($http['body']), 0, 180), $http['code']);
	}

	private function buildWebhookBody($cfg, $title, $text)
	{
		$template = $cfg['body_template'];

		if ($cfg['body_type'] === 'json') {
			if ($template !== '') {
				$rendered = str_replace(
					array('{title}', '{content}', '{title_json}', '{content_json}'),
					array($title, $text, $this->jsonString($title), $this->jsonString($text)),
					$template
				);

				// 模板必须仍是合法 JSON，否则回退默认载荷（照搬上游 custom_webhook 的兜底）
				json_decode($rendered);

				if (json_last_error() === JSON_ERROR_NONE) {
					return $rendered;
				}
			}

			return json_encode(array(
				'title'   => $title,
				'content' => $text,
				'source'  => 'EasyPanel',
				'time'    => date('Y-m-d H:i:s'),
			), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		if ($cfg['body_type'] === 'form') {
			if ($template !== '') {
				return http_build_query(array(
					'title'   => str_replace('{title}', $title, $template),
					'content' => $text,
				));
			}

			return http_build_query(array('title' => $title, 'content' => $text, 'source' => 'EasyPanel'));
		}

		// text
		if ($template !== '') {
			return str_replace(array('{title}', '{content}'), array($title, $text), $template);
		}

		return $title . "\n\n" . $text;
	}

	private function jsonString($s)
	{
		// 去掉首尾引号，供模板内嵌使用
		return substr(json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
	}

	/**
	 * 识别「HTTP 200 但业务失败」的常见机器人平台响应（钉钉/企微/飞书）
	 */
	private function inspectBizError($body)
	{
		$body = trim($body);

		if ($body === '' || $body[0] !== '{') {
			return '';
		}

		$json = json_decode($body, true);

		if (!is_array($json)) {
			return '';
		}

		foreach (array('errcode', 'code', 'errno') as $key) {
			if (array_key_exists($key, $json)) {
				$code = $json[$key];

				if (is_numeric($code) && intval($code) === 0) {
					return '';
				}

				$msg = '';

				foreach (array('errmsg', 'message', 'msg') as $m) {
					if (isset($json[$m]) && is_scalar($json[$m])) {
						$msg = strval($json[$m]);
						break;
					}
				}

				return $key . '=' . $code . ($msg !== '' ? ' ' . $msg : '');
			}
		}

		if (array_key_exists('ok', $json) && empty($json['ok'])) {
			return 'ok=false';
		}

		return '';
	}

	/**
	 * 统一 HTTP 客户端（curl 优先，无 curl 时退 file_get_contents）
	 *
	 * @return array array('ok','code','body','error')
	 */
	private function httpRequest($url, $method, $body, $headers = array(), $timeout = 0)
	{
		$timeout = $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;

		if (function_exists('curl_init')) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
			curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
			curl_setopt($ch, CURLOPT_HEADER, false);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			// 面板部署环境常有自签/中间盒证书；与既有 WHM 调用口径保持一致
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

			if ($method !== 'GET' && $method !== 'HEAD') {
				curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
			}

			$result = curl_exec($ch);
			$code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
			$err = curl_error($ch);
			curl_close($ch);

			if ($result === false) {
				return array('ok' => false, 'code' => $code, 'body' => '', 'error' => $err !== '' ? $err : 'curl 失败');
			}

			return array('ok' => true, 'code' => $code, 'body' => strval($result), 'error' => '');
		}

		// 退路：allow_url_fopen 场景（本容器有 curl，一般不走这里）
		$opts = array('http' => array(
			'method'        => $method,
			'timeout'       => $timeout,
			'follow_location' => 1,
			'ignore_errors' => true,
			'header'        => implode("\r\n", $headers),
		), 'ssl' => array('verify_peer' => false, 'verify_peer_name' => false));

		if ($method !== 'GET' && $body !== '') {
			$opts['http']['content'] = $body;
		}

		$resp = @file_get_contents($url, false, stream_context_create($opts));
		$code = 0;

		if (isset($http_response_header) && is_array($http_response_header) && isset($http_response_header[0])) {
			if (preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
				$code = intval($m[1]);
			}
		}

		if ($resp === false) {
			return array('ok' => false, 'code' => $code, 'body' => '', 'error' => '无法请求 ' . $url);
		}

		return array('ok' => true, 'code' => $code, 'body' => strval($resp), 'error' => '');
	}

	/**
	 * URL 校验（http/https 白名单）
	 *
	 * 不做私网封禁：本功能的核心用法之一就是 webhook 打到
	 * http://host.docker.internal:8099/ 这类宿主/内网目标。
	 */
	private function checkUrl($url)
	{
		if ($url === '') {
			return array('ok' => false, 'msg' => '不能为空');
		}

		$p = parse_url($url);

		if ($p === false || empty($p['host'])) {
			return array('ok' => false, 'msg' => '不是合法的 URL');
		}

		if (!isset($p['scheme']) || !in_array(strtolower($p['scheme']), array('http', 'https'), true)) {
			return array('ok' => false, 'msg' => '仅支持 http/https 协议');
		}

		if (isset($p['port']) && ($p['port'] < 1 || $p['port'] > 65535)) {
			return array('ok' => false, 'msg' => '端口超出范围');
		}

		if (!preg_match('/^[A-Za-z0-9._\-\[\]:]+$/', $p['host'])) {
			return array('ok' => false, 'msg' => '主机名含非法字符');
		}

		return array('ok' => true, 'msg' => '', 'url' => $url);
	}

	/**
	 * 收件人列表解析：逗号/分号/换行混合，逐个 filter_var
	 */
	private function parseEmails($raw)
	{
		$parts = preg_split('/[,;\r\n\s]+/', strval($raw), -1, PREG_SPLIT_NO_EMPTY);
		$list = array();

		foreach ($parts as $email) {
			$email = trim($email);

			// 支持 "Name <a@b.c>" 写法，只取地址部分
			if (preg_match('/<([^>]+)>/', $email, $m)) {
				$email = trim($m[1]);
			}

			if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$list[] = $email;
			}
		}

		return array_values(array_unique($list));
	}

	/**
	 * 自定义请求头解析：每行 `Key: value`，拒绝任何含 CR/LF 的行
	 *
	 * 两种输入都要吃：表单提交来的是文本框原文（每行一条），而库里存的
	 * config 经 json_decode 后已经是 array —— validate() 每次发送都会被再跑
	 * 一遍，若只认文本，库里已有的自定义头会在第二次发送时静默丢失。
	 */
	private function parseHeaders($raw)
	{
		$out = array();

		if (is_array($raw)) {
			foreach ($raw as $key => $val) {
				$line = trim(strval($key)) . ': ' . (is_scalar($val) ? trim(strval($val)) : '');

				if (trim($line, ': ') === '') {
					continue;
				}

				$parsed = $this->parseHeaders($line);
				$out = array_merge($out, $parsed);
			}

			return $out;
		}

		foreach (preg_split('/\r\n|\r|\n/', strval($raw)) as $line) {
			$line = trim($line);

			if ($line === '') {
				continue;
			}

			$pos = strpos($line, ':');

			if ($pos === false) {
				continue;
			}

			$key = trim(substr($line, 0, $pos));
			$val = trim(substr($line, $pos + 1));

			if ($key === '' || !preg_match('/^[A-Za-z0-9\-]+$/', $key)) {
				continue;
			}

			// 下面这些头由本模块自己算或被签名逻辑占用，配置里填了也会被丢弃：
			//   Content-Length / Host / Connection —— 传输层语义，改了就破坏请求
			//   Content-Type —— 由「正文格式」唯一决定，重复设置会让接收端取错值
			//   X-Ep-Signature / X-Ep-Timestamp —— 伪造它们等于自己骗过自己的签名校验
			$lower = strtolower($key);

			if (in_array($lower, array('content-length', 'content-type', 'host', 'connection',
				'x-ep-signature', 'x-ep-timestamp'), true)) {
				continue;
			}

			$out[$key] = preg_replace('/[\r\n]/', '', $val);
		}

		return $out;
	}

	private function normTimeout($v)
	{
		$v = intval($v);

		if ($v <= 0) {
			return self::DEFAULT_TIMEOUT;
		}

		return $v > 30 ? 30 : $v;
	}

	/**
	 * 解出渠道配置（config 列 JSON → 数组）
	 */
	public function decodeConfig($channel)
	{
		if (!is_array($channel) || !isset($channel['config'])) {
			return array();
		}

		$raw = strval($channel['config']);

		if ($raw === '') {
			return array();
		}

		$json = json_decode($raw, true);

		return is_array($json) ? $json : array();
	}

	private function fail($msg, $http_code = 0)
	{
		// 错误文案是「第三方原文 + 我方中文说明」拼出来的：GBK 的 SMTP 应答、
		// 非 UTF-8 的 webhook body 都会混进非法字节。非法 UTF-8 会让下游
		// json_encode 直接返回 false（接口空响应）、也会让按字节截断切坏字符。
		// 所以所有失败文案都必须先过 utf8Safe 这一道。
		return array('success' => false, 'error' => $this->utf8Safe($msg), 'http_code' => intval($http_code));
	}

	/**
	 * 把任意字节洗成合法 UTF-8（绝不返回 false / 绝不丢光内容）
	 *
	 * 三级回落（实测依据：容器 PHP 7.4 里 JSON_INVALID_UTF8_SUBSTITUTE 已定义；
	 * iconv('UTF-8','UTF-8//IGNORE', 纯垃圾字节) 会返回 false，所以不能只靠它）：
	 *   1) iconv //IGNORE 丢弃非法字节（保留可读中文部分，最理想）
	 *   2) iconv //TRANSLIT
	 *   3) 逐字节白名单：非可打印 ASCII 一律换成 '?'，保证一定有输出
	 */
	private function utf8Safe($s)
	{
		$s = strval($s);

		if ($s === '') {
			return '';
		}

		// 已经是合法 UTF-8 就别折腾（省两次 iconv 调用）
		if (function_exists('mb_check_encoding') && mb_check_encoding($s, 'UTF-8')) {
			return $s;
		}

		if (function_exists('iconv')) {
			$c = @iconv('UTF-8', 'UTF-8//IGNORE', $s);

			if (is_string($c) && $c !== '') {
				return $c;
			}

			$c = @iconv('UTF-8', 'UTF-8//TRANSLIT', $s);

			if (is_string($c) && $c !== '') {
				return $c;
			}
		}

		$c = preg_replace('/[^\x20-\x7E]/', '?', $s);

		return $c === null ? '?' : $c;
	}

	private function failResult($id, $name, $type, $msg)
	{
		return array(
			'id'        => $id,
			'name'      => $name,
			'type'      => $type,
			'status'    => 'failed',
			'success'   => false,
			'error'     => $msg,
			'http_code' => 0,
			'msg'       => $msg,
		);
	}

	private function oneLine($s)
	{
		return trim(preg_replace('/\s+/', ' ', strval($s)));
	}

	private function testHtml($channel, $text)
	{
		if ($channel['type'] !== 'smtp') {
			return '';
		}

		$p = nl2br($this->normalizeText($text));

		return '<div style="font-family:Menlo,Consolas,monospace;font-size:13px;line-height:1.7">' . $p
			. '<hr><span style="color:#888">EasyPanel 通知渠道 · notify_channel</span></div>';
	}

	private function localName()
	{
		$name = daocall('setting', 'get', array('local_cdn_name'));

		if ($name) {
			return strval($name);
		}

		if (isset($_SERVER['SERVER_NAME'])) {
			return strval($_SERVER['SERVER_NAME']);
		}

		return gethostname();
	}
}

?>
