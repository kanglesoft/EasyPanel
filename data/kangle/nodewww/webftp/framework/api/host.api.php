<?php
/**
 * HostApi — 宿主机级操作（SSH 端口 / 服务开关 / root 密码）
 *
 * 前置：kangle 容器需以 pid: host + cap_add(SYS_ADMIN, DAC_OVERRIDE, DAC_READ_SEARCH)
 * 运行，且镜像内已安装 util-linux（提供 nsenter）。
 *
 * 所有操作通过 `nsenter -t 1 -m -u -i -n -p --` 直接进入宿主 init 的命名空间执行，
 * 因此读写的就是宿主真实的 /etc/ssh/sshd_config、/etc/shadow 与 systemd 服务。
 * 该方式无需在宿主额外部署 agent，也不依赖任何外部镜像，不会污染宿主文件系统
 * （除按预期修改 sshd_config / shadow 外）。
 *
 * 安全说明：此类操作极为敏感，调用方（HostControl）已强制 needRole('admin')，
 * 前端亦做二次确认。生产环境建议在此基础上再加审计日志与 CSRF 校验。
 */
class HostApi extends API
{
	private $docker_bin = '/usr/bin/docker';
	private $helper_image = 'busybox:stable';

	/**
	 * 在宿主命名空间内执行一条 shell 命令。
	 * 实现：经 docker.sock 调宿主守护进程，启动一个一次性特权子容器（busybox + nsenter），
	 * 以 --pid=host 进入宿主 init 的命名空间执行。kangle 容器本身保持常规权限，
	 * 从而不影响容器内原有功能。
	 */
	private function hostExec($shellCmd)
	{
		if (!$this->ensureHelper()) {
			return array('code' => 1, 'output' => '宿主操作依赖的辅助镜像 ' . $this->helper_image . ' 不可用，且无法自动拉取（请检查宿主网络或手动执行 docker pull ' . $this->helper_image . '）。');
		}
		$inner = escapeshellarg($shellCmd);
		$cmd = $this->docker_bin . ' run --rm --privileged --pid=host --network=host '
			. escapeshellarg($this->helper_image)
			. ' nsenter -t 1 -m -u -i -n -p -- sh -c ' . $inner . ' 2>&1';
		exec($cmd, $out, $code);
		return array('code' => (int)$code, 'output' => implode("\n", $out));
	}

	private function runDocker($args)
	{
		$cmd = $this->docker_bin . ' ' . $args . ' 2>&1';
		exec($cmd, $out, $code);
		return array('code' => (int)$code, 'output' => implode("\n", $out));
	}

	/**
	 * 确保宿主操作所需的辅助镜像可用（缺失时尝试拉取一次）。
	 */
	private function ensureHelper()
	{
		$img = escapeshellarg($this->helper_image);
		$r = $this->runDocker('image inspect ' . $img . ' >/dev/null 2>&1');
		if ($r['code'] === 0) {
			return true;
		}
		$p = $this->runDocker('pull ' . $img . ' 2>&1');
		return $p['code'] === 0;
	}

	/**
	 * 判断宿主命令是否真正执行（排除 nsenter 缺失 / 权限不足等失败）。
	 */
	private function hostOk($r, $okCodes = array(0))
	{
		if (!in_array($r['code'], $okCodes, true)) {
			return false;
		}
		$o = strtolower($r['output']);
		if (strpos($o, 'nsenter') !== false && (strpos($o, 'not found') !== false || strpos($o, 'failed') !== false || strpos($o, 'operation not permitted') !== false)) {
			return false;
		}
		return true;
	}

	/**
	 * 宿主是否启用 systemd socket activation 接管 SSH 监听。
	 *
	 * Ubuntu 22.10+ / Debian 13+ 默认启用 ssh.socket，此时实际监听端口由
	 * socket 的 ListenStream 决定，sshd_config 中的 Port 会被完全忽略。
	 * 若不识别该模式，就会出现「面板提示改端口成功、实际端口没变」的危险
	 * 不一致——用户据此在云安全组封掉旧端口即刻失联。
	 *
	 * @return bool
	 */
	private function sshSocketActive()
	{
		// ⚠️ 此处刻意不使用 shell 变量与 $(...) 命令替换：本文件的命令串是 PHP
		// 双引号字符串，`$a` / `$(cmd)` 中的 `$x` 会被 PHP 当作变量插值成空串，
		// 导致命令语义被悄悄改写（曾因此让本方法恒返回 false）。
		// 一律改为 printf + 直接执行的拼接形式，不给插值留机会。
		$r = $this->hostExec(
			"if systemctl cat ssh.socket >/dev/null 2>&1; then "
		  . "printf 'unit=yes active='; systemctl is-active ssh.socket 2>/dev/null || true; "
		  . "printf 'enabled='; systemctl is-enabled ssh.socket 2>/dev/null || true; "
		  . "else echo 'unit=no'; fi"
		);
		if (!$this->hostOk($r, array(0, 1))) {
			return false;
		}
		$o = strtolower($r['output']);
		if (strpos($o, 'unit=no') !== false) {
			return false;
		}
		return (strpos($o, 'active=active') !== false || strpos($o, 'enabled=enabled') !== false);
	}

	/**
	 * 同步 ssh.socket 的监听端口（socket activation 模式必需）。
	 *
	 * 采用 drop-in 覆盖而非直接改发行版单元文件，避免被系统升级覆盖；
	 * 先以空 ListenStream= 清空继承值，再写入新端口，否则会变成"同时监听
	 * 旧端口与新端口"。
	 *
	 * @param int $port
	 * @return array hostExec 结果
	 */
	private function syncSshSocketPort($port)
	{
		$p = (int)$port;
		$conf = "[Socket]\n"
		      . "ListenStream=\n"
		      . "ListenStream=0.0.0.0:" . $p . "\n"
		      . "ListenStream=[::]:" . $p . "\n";

		return $this->hostExec(
			"mkdir -p /etc/systemd/system/ssh.socket.d && "
		  . "printf '%s' " . escapeshellarg($conf) . " > /etc/systemd/system/ssh.socket.d/override.conf && "
		  . "systemctl daemon-reload && "
		  . "(systemctl restart ssh.socket 2>&1 || true); "
		  . "systemctl is-active ssh.socket 2>/dev/null; "
		  . "systemctl show ssh.socket -p Listen 2>/dev/null"
		);
	}

	/**
	 * 读取 ssh.socket 实际生效的监听端口。
	 *
	 * @return int 0 表示未取到
	 */
	private function sshSocketPort()
	{
		$r = $this->hostExec("systemctl show ssh.socket -p Listen 2>/dev/null");
		if (!$this->hostOk($r, array(0, 1))) {
			return 0;
		}
		// 形如：Listen=0.0.0.0:22 (Stream) / Listen=[::]:22 (Stream)
		if (preg_match('/Listen=\S*?:(\d+)\s/', $r['output'], $m)) {
			return (int)$m[1];
		}
		return 0;
	}

	public function getSshPort()
	{
		// socket activation 模式下，真正生效的是 ssh.socket 的 ListenStream，
		// sshd_config 的 Port 只是"看起来"的值。此处必须以实际监听为准，
		// 否则页面显示的端口与真实端口不一致，会误导用户封错端口。
		if ($this->detectSshMode() === 'socket') {
			$sp = $this->sshSocketPort();
			if ($sp > 0) {
				return array(
					'success' => true,
					'port'    => $sp,
					'raw'     => 'ssh.socket ListenStream=' . $sp,
					'mode'    => 'socket',
				);
			}
		}

		// grep 无匹配时退出码为 1，属正常（表示使用默认 22），故 okCodes 含 1
		$r = $this->hostExec("grep -E '^#?Port' /etc/ssh/sshd_config 2>/dev/null | tail -n1");
		if (!$this->hostOk($r, array(0, 1))) {
			return array('success' => false, 'msg' => '无法读取 sshd_config：' . trim($r['output']));
		}
		$line = trim($r['output']);
		if ($line === '') {
			return array('success' => true, 'port' => 22, 'raw' => '');
		}
		if (!preg_match('/Port\s+(\d+)/i', $line, $m)) {
			return array('success' => true, 'port' => 22, 'raw' => $line);
		}
		$port = (int)$m[1];
		if ($port < 1 || $port > 65535) {
			$port = 22;
		}
		return array('success' => true, 'port' => $port, 'raw' => $line);
	}

	public function setSshPort($port)
	{
		$port = (int)$port;
		if ($port < 1 || $port > 65535) {
			return array('success' => false, 'msg' => '端口必须在 1-65535 之间');
		}
		// 避免与面板/数据库已暴露端口冲突，造成自身失联
		if (in_array($port, array(80, 443, 3306, 3311, 3312, 3313), true)) {
			return array('success' => false, 'msg' => '该端口与面板/数据库端口冲突，请另选');
		}

		// 1-C：改端口有「失败即失联」的风险，先把旧端口读出来写进审计，
		// 事后才能凭日志知道该回退到哪个端口。读取失败不阻断（old_port=0 表示未知）。
		$old = $this->getSshPort();
		$oldPort = (is_array($old) && !empty($old['success']) && isset($old['port'])) ? intval($old['port']) : 0;

		$r = $this->hostExec(
			"sed -i -E 's/^#?Port[[:space:]]+[0-9]+/Port " . $port . "/' /etc/ssh/sshd_config; "
		  . "if ! grep -qE '^Port[[:space:]]+" . $port . "' /etc/ssh/sshd_config; then printf 'Port %d\\n' " . $port . " >> /etc/ssh/sshd_config; fi; "
		  . "grep -E '^Port' /etc/ssh/sshd_config | tail -n1"
		);
		if ($r['code'] !== 0) {
			$this->auditHost('setSshPort', (string)$port, 'error', array(
				'old_port' => $oldPort,
				'msg'      => trim($r['output']),
			));
			return array('success' => false, 'msg' => '写入 sshd_config 失败：' . trim($r['output']));
		}

		// socket activation 模式下 sshd_config 的 Port 不生效，必须同步
		// ssh.socket 的 ListenStream，否则会出现「提示成功、端口未变」。
		// 顺序很重要：先放行防火墙，再切换监听端口，避免新端口被挡在门外。
		$this->openFirewallPort($port);

		$socketMode = ($this->detectSshMode() === 'socket');
		if ($socketMode) {
			$this->syncSshSocketPort($port);
		}
		$this->restartSshd();

		// 回读实际监听端口做闭环校验：宁可返回失败让用户察觉，
		// 也不能在端口没变的情况下报成功——那会导致用户封掉旧端口后失联。
		$actual = $this->getSshPort();
		$actualPort = (is_array($actual) && isset($actual['port'])) ? intval($actual['port']) : 0;
		if ($socketMode && $actualPort !== $port) {
			$this->auditHost('setSshPort', (string)$port, 'error', array(
				'old_port'    => $oldPort,
				'actual_port' => $actualPort,
				'msg'         => 'ssh.socket 端口同步失败',
			));
			return array(
				'success' => false,
				'msg'     => '配置已写入，但宿主机 ssh.socket 实际监听端口仍为 ' . ($actualPort ?: '未知')
				           . '，端口未生效。请勿在云安全组封闭原端口，并联系管理员检查 ssh.socket。',
			);
		}

		$this->auditHost('setSshPort', (string)$port, 'success', array(
			'old_port'    => $oldPort,
			'actual_port' => $actualPort,
			'mode'        => $socketMode ? 'socket' : 'sshd_config',
		));

		return array(
			'success' => true,
			'msg'     => 'SSH 端口已修改为 ' . $port . '（已核实宿主机实际监听生效）'
			           . '，并已尝试放行防火墙。请确认云安全组也已放行新端口后再断开当前连接。',
		);
	}

	public function getSshStatus()
	{
		// socket activation 模式下 sshd.service 平时是 inactive（按连接才拉起
		// ssh@.service 实例），若仍按 service 状态判断会误报「已停止」，
		// 用户会以为 SSH 关了而去做多余操作。
		// 反之，若 ssh.service 曾被直接启动，socket 单元会被「消耗」成 inactive，
		// 此时只看 socket 又会误报已停止。故两者取「或」：任一在跑即为可用。
		if ($this->sshSocketActive()) {
			$s = $this->hostExec(
				"printf 'sock='; systemctl is-active ssh.socket 2>/dev/null || true; "
			  . "printf 'svc='; systemctl is-active ssh.service 2>/dev/null || true"
			);
			$so = strtolower($s['output']);
			$running = (strpos($so, 'sock=active') !== false || strpos($so, 'svc=active') !== false);
			return array(
				'success' => true,
				'running' => $running,
				'raw'     => trim(str_replace("\n", ' ', $s['output'])),
				'mode'    => 'socket',
			);
		}

		$r = $this->hostExec("if command -v systemctl >/dev/null 2>&1; then systemctl is-active sshd 2>/dev/null || systemctl is-active ssh 2>/dev/null; else service ssh status 2>/dev/null | head -1; fi");
		if (!$this->hostOk($r)) {
			return array('success' => false, 'msg' => '无法获取 SSH 服务状态：' . trim($r['output']));
		}
		$out = strtolower(trim($r['output']));
		$running = ($out === 'active' || $out === 'running' || strpos($out, 'running') !== false || strpos($out, '(pid') !== false);
		return array('success' => true, 'running' => (bool)$running, 'raw' => $r['output']);
	}

	public function setSshEnabled($enabled)
	{
		$on = $enabled ? true : false;

		// ⚠️ 关键：ssh.socket 与 ssh.service 抢同一个 22 端口，且 ssh.socket 的
		// [Install] 含 RequiredBy=ssh.service。若像早期实现那样「两个都 enable、
		// 两个都 start」，实测会出现：
		//   - `systemctl enable sshd` → Refusing to operate on linked unit file
		//   - `systemctl start ssh`   → A dependency job for ssh.service failed
		//   - 最终宿主从 socket 模式漂移成 service 模式，且 ssh.socket 变 disabled
		//     （重启宿主后按原模式无法自启，存在失联风险）
		// 因此必须先判定宿主原本的启动模式，再二选一操作，绝不同时动两个单元。
		$mode = $this->detectSshMode();

		if (!$on) {
			// 关闭前把模式落盘：关闭会把 socket 置为 disabled，
			// 此后运行时探测已无法还原「原本是 socket 模式」这一事实。
			$this->rememberSshMode($mode);
		}

		if ($mode === 'socket') {
			if ($on) {
				// 顺序：先停可能占用端口的 service，再拉起 socket，
				// 否则 socket 会因 Address already in use 启动失败。
				// ssh.service 为 KillMode=process，stop 不会踢掉已建立会话。
				$cmd = "systemctl stop ssh.service 2>/dev/null; "
				     . "systemctl enable ssh.socket 2>/dev/null; "
				     . "systemctl start ssh.socket 2>/dev/null; ";
			} else {
				// 只停 service 关不掉：下个连接进来 socket 会重新拉起实例。
				// 必须停 socket + 停已激活的 ssh@*.service 实例。
				$cmd = "systemctl stop ssh.socket 2>/dev/null; "
				     . "systemctl disable ssh.socket 2>/dev/null; "
				     . "systemctl stop 'ssh@*.service' 2>/dev/null; "
				     . "systemctl stop ssh.service 2>/dev/null; ";
			}
		} else {
			// service 模式：不要碰 ssh.socket，避免把宿主推入 socket 模式。
			// 注意 sshd 多为 ssh.service 的 Alias，enable/disable 需针对 ssh。
			$en  = $on ? 'enable' : 'disable';
			$svc = $on ? 'start' : 'stop';
			$cmd = "systemctl " . $en . " ssh 2>/dev/null || systemctl " . $en . " sshd 2>/dev/null; "
			     . "systemctl " . $svc . " ssh 2>/dev/null || systemctl " . $svc . " sshd 2>/dev/null; ";
		}

		$r = $this->hostExec(
			"if command -v systemctl >/dev/null 2>&1; then " . $cmd
		  . "else service ssh " . ($on ? 'start' : 'stop') . " 2>/dev/null || service sshd " . ($on ? 'start' : 'stop') . " 2>/dev/null; fi; echo done"
		);
		if ($r['code'] !== 0) {
			$this->auditHost('setSshEnabled', $on ? 'on' : 'off', 'error', array(
				'mode' => $mode,
				'msg'  => trim($r['output']),
			));
			return array('success' => false, 'msg' => '操作失败：' . trim($r['output']));
		}

		// 闭环校验：以实际监听结果为准，避免「显示已开启但其实没起来」。
		$st = $this->getSshStatus();
		$running = (is_array($st) && !empty($st['success'])) ? !empty($st['running']) : null;

		if ($running !== null && $running !== $on) {
			$this->auditHost('setSshEnabled', $on ? 'on' : 'off', 'error', array(
				'mode'   => $mode,
				'actual' => $running ? 'running' : 'stopped',
			));
			return array(
				'success' => false,
				'msg'     => ($on ? '开启' : '关闭') . ' SSH 的指令已下发，但宿主机实际状态仍为「'
				           . ($running ? '运行中' : '已停止') . '」，操作未生效。请勿据此调整安全组或断开当前连接。',
			);
		}

		// 恢复成功后清掉模式标记，避免陈旧标记在宿主模式变更后误导后续判断
		if ($on) {
			$this->hostExec("rm -f /etc/easypanel/ssh_mode 2>/dev/null; echo done");
		}

		$this->auditHost('setSshEnabled', $on ? 'on' : 'off', 'success', array('mode' => $mode));

		return array(
			'success' => true,
			'msg'     => ($on ? '已开启' : '已关闭') . ' SSH 服务（已核实宿主机实际状态，启动模式：' . $mode . '）',
		);
	}

	/**
	 * 判定宿主 SSH 的启动模式：socket activation 还是传统 service。
	 *
	 * 为什么不能只靠运行时探测：一旦执行过「一键关闭」，ssh.socket 会被
	 * disable，此时运行时探测只会得出 service 模式，再「一键开启」就会把
	 * 宿主从 socket 模式永久改成 service 模式（且 socket 保持 disabled，
	 * 宿主重启后按原模式起不来）。故关闭时落盘、开启时读回。
	 *
	 * @return string 'socket'|'service'
	 */
	private function detectSshMode()
	{
		$r = $this->hostExec("cat /etc/easypanel/ssh_mode 2>/dev/null | head -n1");
		$m = trim(strtolower($r['output']));
		if ($m === 'socket' || $m === 'service') {
			return $m;
		}

		return $this->sshSocketActive() ? 'socket' : 'service';
	}

	/**
	 * 记录宿主 SSH 启动模式（供「关闭后再开启」按原模式恢复）。
	 *
	 * @param string $mode 'socket'|'service'
	 * @return void
	 */
	private function rememberSshMode($mode)
	{
		$m = ($mode === 'socket') ? 'socket' : 'service';
		$this->hostExec(
			"mkdir -p /etc/easypanel && printf '%s\\n' " . escapeshellarg($m) . " > /etc/easypanel/ssh_mode; echo done"
		);
	}

	public function changeRootPassword($pass)
	{
		if (!is_string($pass) || strlen($pass) < 6 || strlen($pass) > 128) {
			return array('success' => false, 'msg' => '密码长度需在 6-128 之间');
		}
		if (strpos($pass, "\0") !== false) {
			return array('success' => false, 'msg' => '密码包含非法字符');
		}

		// 生成 sha512 crypt 哈希（兼容 /etc/shadow），哈希仅含 [./A-Za-z0-9]，
		// 通过 chpasswd -e 写入，明文密码不进入 shell，避免注入。
		$salt = '$6$' . strtr(substr(base64_encode(random_bytes(12)), 0, 16), '+/', '..') . '$';
		$hash = crypt($pass, $salt);
		if (!is_string($hash) || strlen($hash) < 10 || strpos($hash, '$6$') !== 0) {
			return array('success' => false, 'msg' => '密码哈希生成失败');
		}

		$r = $this->hostExec("printf 'root:%s' " . escapeshellarg($hash) . " | chpasswd -e");
		if ($r['code'] !== 0) {
			// ⚠️ 审计里绝不写入 $pass 或 $hash：审计表是明文存储且可被翻阅，
			// 把凭据写进去等于把「改密码」这个动作变成「公开新密码」。
			$this->auditHost('changeRootPassword', 'root', 'error', array('msg' => trim($r['output'])));
			return array('success' => false, 'msg' => '修改失败：' . trim($r['output']));
		}

		$this->auditHost('changeRootPassword', 'root', 'success', array());

		return array('success' => true, 'msg' => '宿主机 root 密码已更新');
	}

	/**
	 * 宿主级操作审计的统一出口（1-B）
	 *
	 * 收口成一个方法，是为了让「哪些信息禁止入审计」只在一个地方被约束，
	 * 而不是散落在每个调用点各自凭自觉。审计本身失败静默，不阻断业务。
	 *
	 * @param string $action 操作名，与 csrf_enforce_level 的 action 保持同名
	 * @param string $target 操作对象
	 * @param string $result success|error
	 * @param array  $detail 附加信息（**禁止包含任何凭据**）
	 * @return void
	 */
	private function auditHost($action, $target, $result, $detail = array())
	{
		if (!function_exists('audit')) {
			return;
		}

		audit('host', $action, $target, $result, $detail);
	}

	private function restartSshd()
	{
		return $this->hostExec("if command -v systemctl >/dev/null 2>&1; then systemctl restart sshd 2>/dev/null || systemctl restart ssh 2>/dev/null; else service ssh restart 2>/dev/null || service sshd restart 2>/dev/null; fi; echo done");
	}

	private function openFirewallPort($port)
	{
		$p = (int)$port;
		return $this->hostExec(
			"if command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active --quiet firewalld; then firewall-cmd --add-port=" . $p . "/tcp --permanent; firewall-cmd --reload; "
		  . "elif command -v ufw >/dev/null 2>&1; then ufw allow " . $p . "/tcp; "
		  . "elif command -v iptables >/dev/null 2>&1; then iptables -I INPUT -p tcp --dport " . $p . " -j ACCEPT; fi; echo done"
		);
	}
}
?>
