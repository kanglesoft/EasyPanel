<?php
needRole('admin');
class SettingControl extends Control
{
	public function setFrom()
	{
		return $this->fetch('setting/setFrom.html');
	}

	public function index()
	{
		@load_conf('pub:settingrule');
		@load_conf('pub:setting');

		$settingrule = (isset($GLOBALS['settingrule']) && is_array($GLOBALS['settingrule']))
			? $GLOBALS['settingrule'] : array();
		$setting_cfg = (isset($GLOBALS['setting_cfg']) && is_array($GLOBALS['setting_cfg']))
			? $GLOBALS['setting_cfg'] : array();

		$sub = isset($_REQUEST['sub']) ? trim(strval($_REQUEST['sub'])) : '';

		// 配置文件（settingrule）缺失时优雅提示，避免裸 500 / 空白页
		if (empty($settingrule)) {
			$this->assign('msg', '系统设置规则未配置（settingrule 配置文件缺失），请联系管理员或完成安装初始化。');
			$this->assign('env', array());
			$this->assign('val', $setting_cfg);
			$this->assign('sub', $sub);
			return $this->fetch('setting/show.html');
		}

		// 未指定 sub 或指定的分组不存在时，默认取第一个分组
		if ($sub === '' || !isset($settingrule[$sub])) {
			$keys = array_keys($settingrule);
			$sub = $keys[0];
		}

		$info = $settingrule[$sub];
		$this->assign('env', $info);
		$this->assign('val', $setting_cfg);
		$this->assign('sub', $sub);
		return $this->fetch('setting/show.html');
	}

	public function add()
	{
		daocall('setting', 'add', array($_REQUEST['name'], $_REQUEST['value']));
	}

	public function set()
	{
		@load_conf('pub:settingrule');
		$names = $_REQUEST['name'];
		$sub = $_REQUEST['sub'];

		foreach ($names as $name) {
			if ($GLOBALS['settingrule'][$sub][$name]['password']) {
				if ($_REQUEST[$name] == '') {
					continue;
				}
			}

			$ret = apicall('tplenv', 'checkEnv', array($name, $_REQUEST[$name], $GLOBALS['settingrule'][$sub]));

			if ($ret != ENV_CHECK_SUCCESS) {
				$this->_tpl->assign('msg', '设置:' . $GLOBALS['lang']['zh_CN'][$name] . ' 失败');
				$list = daocall('setting', 'getAll');
				apicall('utils', 'writeConfig', array($list, 'name', 'setting'));
				return $this->index();
			}

			daocall('setting', 'add', array($name, $_REQUEST[$name]));
		}

		$list = daocall('setting', 'getAll');
		apicall('utils', 'writeConfig', array($list, 'name', 'setting'));
		return $this->index();
	}
}

?>