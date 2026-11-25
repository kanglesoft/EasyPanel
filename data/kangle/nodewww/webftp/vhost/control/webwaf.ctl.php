<?php
/**
 * webwaf.ctl.php —— 雷池 WAF（SafeLine）Web 安全防护（空间用户侧，代码保留版）
 *
 * 入口策略（hide 约定）：本单元不往 common/head.html 加导航项、不改
 * docker-compose.yml 挂载（约定见 .temp/integration/safeline-waf.md）；
 * URL ?c=webwaf&a=show 本身可访问，未探测到雷池时展示引导提示页。
 *
 * 保留但不启用上游的链写入代码：webwafFrom/webwafSwitch 逻辑照抄自
 * .temp/repos/easypanel/vhost/control/webwaf.ctl.php（!safeline_waf 表 +
 * acl_safeline_waf + mark_status_code(451) + BEGIN 链）。差异仅一处：
 * webwafSwitch 在写链前先过 apicall('webwaf','probe')，当前内核/宿主
 * 未部署雷池时直接拒绝并审计，绝不产生残链；外部部署雷池 + 内核升级
 * 后，本文件零改动即可用。
 *
 * 新增 status/recheck 两个只读 JSON 事件：返回四键
 * {code, installed, reason, endpoint}，供界面与远端验收断言使用，
 * 不依赖页面可达性。
 */
needRole('vhost');
class WebwafControl extends Control
{
	const TABLENAME = 'table:!safeline_waf';
	const ACTION = '!safeline_waf';
	private $access;

	public function __construct()
	{
		parent::__construct();
		load_lib('pub:access');
		load_api('webwaf');
		$this->access = new Access(getRole('vhost'));
	}

	/**
	 * 开关页：探测通过则渲染表单，否则展示「未检测到雷池」引导提示页
	 */
	public function webwafFrom()
	{
		$check_result = apicall('access', 'checkAccess', array());

		if ($check_result !== true) {
			$this->_tpl->assign('msg', $check_result);
			return $this->_tpl->fetch('msg.html');
		}

		$probe = apicall('webwaf', 'probe', array(false));
		$installed = !empty($probe['installed']);
		$this->_tpl->assign('installed', $installed ? 1 : 0);
		$this->_tpl->assign('reason', $this->reasonText(isset($probe['reason']) ? $probe['reason'] : ''));
		$this->_tpl->assign('endpoint', isset($probe['endpoint']) ? $probe['endpoint'] : '');
		$this->_tpl->assign('vhost', getRole('vhost'));

		if ($installed) {
			$tables = $this->access->listTable();

			if ($tables === false) {
				$this->_tpl->assign('msg', '读取请求控制规则失败');
				return $this->_tpl->fetch('msg.html');
			}

			$enabled = (bool) $this->access->findChain('BEGIN', self::ACTION);
			$this->_tpl->assign('enabled', $enabled ? 1 : 0);
		}

		return $this->_tpl->fetch('webwaf/form.html');
	}

	/**
	 * 探测状态 JSON（只读；四键 code/installed/reason/endpoint 恒定齐全）
	 *
	 * 供远端验收 `?c=webwaf&a=status` curl 断言与界面轮询共用，
	 * 不依赖页面/表单可达性。
	 */
	public function status()
	{
		$probe = apicall('webwaf', 'probe', array(false));
		exit(json_encode(apicall('webwaf', 'buildStatus', array($probe, 0))));
	}

	/**
	 * 重新探测（清缓存后再探测，仍返回 status 同款四键 JSON；ajax）
	 */
	public function recheck()
	{
		$vhost = getRole('vhost');
		$probe = apicall('webwaf', 'probe', array(true));
		audit('webwaf', 'recheck', $vhost, 'success', array('installed' => !empty($probe['installed'])));
		exit(json_encode(apicall('webwaf', 'buildStatus', array($probe, 0))));
	}

	/**
	 * 开关（ajax）——上游逻辑保留，前置探测总闸
	 */
	public function webwafSwitch()
	{
		$vhost = getRole('vhost');

		if (apicall('access', 'checkAccess', array()) !== true) {
			audit('webwaf', 'switch', $vhost, 'deny', array('reason' => 'access_denied'));
			exit('您的空间不支持该功能，请联系管理员');
		}

		$status = isset($_POST['status']) ? (int) $_POST['status'] : 0;

		if ($status !== 1 && $status !== 2) {
			exit('开关状态无效');
		}

		// hide 版总闸：探测不通过时拒绝写链（当前内核零命中 safeline，
		// 直接写 acl_safeline_waf 链会让内核加载未知 model 而报错/残链）
		$probe = apicall('webwaf', 'probe', array(false));

		if (empty($probe['installed'])) {
			audit('webwaf', 'switch', $vhost, 'deny', array('reason' => (string) $probe['reason'], 'status' => $status));
			exit('请先安装并启动雷池WAF');
		}

		$enabled = (bool) $this->access->findChain('BEGIN', self::ACTION);

		if ($status === 1) {
			if (!$this->ensureTable()) {
				audit('webwaf', 'switch', $vhost, 'error', array('stage' => 'add_table', 'status' => $status));
				exit('创建Web安全防护规则表失败');
			}

			if (!$this->addRule()) {
				audit('webwaf', 'switch', $vhost, 'error', array('stage' => 'add_chain', 'status' => $status));
				exit('初始化Web安全防护设置失败');
			}

			if (!$this->linkBegin()) {
				audit('webwaf', 'switch', $vhost, 'error', array('stage' => 'link_begin', 'status' => $status));
				exit('开启Web安全防护失败');
			}

			audit('webwaf', 'switch', $vhost, 'success', array('status' => 1));
		} elseif ($status === 2 && $enabled) {
			if (!$this->access->delChainByName('BEGIN', self::ACTION)) {
				audit('webwaf', 'switch', $vhost, 'error', array('stage' => 'del_begin', 'status' => $status));
				exit('关闭Web安全防护失败');
			}

			audit('webwaf', 'switch', $vhost, 'success', array('status' => 2));
		}

		$this->sync();
		exit('成功');
	}

	/**
	 * 机器可读 reason 码 → 界面文案（api 头注释 4：断言只依赖 reason 码，
	 * 展示文案在此映射）
	 */
	private function reasonText($reason)
	{
		$reason = strval($reason);

		if ($reason === 'ok') {
			return '已检测到雷池WAF服务';
		}

		if (strpos($reason, 'sock') !== false) {
			return '未检测到雷池WAF服务（detector snserver.sock 不存在，需宿主安装雷池并以卷挂载 /data/safeline 进容器）';
		}

		if (strpos($reason, 'dso_xml') !== false) {
			return '未检测到雷池WAF服务（ext/safeline.dso.xml 缺失，需为内核部署雷池 DSO 配置）';
		}

		if (strpos($reason, 'kernel') !== false) {
			return '未检测到雷池WAF服务（当前 kangle 内核未编译 acl_safeline_waf 支持）';
		}

		return '未检测到雷池WAF服务（' . htmlspecialchars($reason, ENT_QUOTES) . '）';
	}

	private function addRule()
	{
		$models = array();
		$models['acl_safeline_waf'] = array('timeout_ms' => 1000);
		$models['mark_status_code'] = array('code' => 451);
		// WHM's edit_chain upserts by name, so updating an active rule is atomic.
		return $this->access->editChain(self::ACTION, array('action' => 'allow', 'name' => self::ACTION), $models);
	}

	private function linkBegin()
	{
		return $this->access->editChain('BEGIN', array('action' => self::TABLENAME, 'name' => self::ACTION));
	}

	private function ensureTable()
	{
		$tables = $this->access->listTable();
		return $tables !== false && (in_array(self::ACTION, $tables, true) || $this->access->addTable(self::ACTION));
	}

	private function sync()
	{
		apicall('vhost', 'updateVhostSyncseq', array(getRole('vhost')));
	}
}

?>
