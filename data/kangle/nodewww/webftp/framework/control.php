<?php
class Control
{
	static public $__instance;
	static public $__out;
	static public $_tpl;

	/**
	 * 控制器构造函数
	 *
	 * 当子类实现自己的控制器构造函数时，必须在构造函数体内第一行调用： parent::__construct();
	 */
	public function __construct()
	{
		global $__core_env;
		$rand = rand(1000, 9999);
		$__core_env['__LIUSHUIHAO__'] = time() . $UID . $rand;
		$this->__out = &$__core_env['out'];
		$this->_tpl = tpl::singleton();

		// CSRF 中央守卫（AD-2 / D1）。
		// 为什么挂在这里：dispatch 里 `$inst = new $class();` 是无条件执行的，
		// 且全部 32 个自定义构造函数的控制器都调用了 parent::__construct()（已核实）。
		// 因此这一处挂载即可覆盖所有控制器，无需逐个改造 31 个 ctl 文件。
		// 用 function_exists 兜底，保证在 runtime.php 未被加载的极个别场景下不致命。
		if (function_exists('csrf_guard')) {
			csrf_guard();
		}

		// AD-4：向模板注入 token。
		// 为什么在构造函数里也注入一次（fetch() 里还有一次）：
		// 项目里大量控制器用的是 `$this->_tpl->display(...)` 而不是
		// `$this->display(...)`，前者直接调 Smarty、绕过了 Control::fetch()，
		// 只在 fetch() 里注入会让这些页面拿不到 token。
		// $this->_tpl 是 tpl::singleton() 返回的全局单例，此处注入对后续
		// 所有模板（含 fetch() 路径）均生效，且两处注入值相同、幂等。
		if (function_exists('csrf_token')) {
			$this->_tpl->assign('csrf_token', csrf_token());
		}
	}

	/**
	 * 控制器构造函数
	 *
	 * 当子类实现自己的控制器析构函数时，必须在析构函数体内最后一行调用： parent::__destruct();
	 */
	public function __destruct()
	{
		global $__core_env;
	}

	public function assign($tpl_var, $value = null, $nocache = false)
	{
		return $this->_tpl->assign($tpl_var, $value, $nocache);
	}

	public function display($template)
	{
		echo $this->fetch($template);
	}

	public function fetch($template)
	{
		$locale = 'zh_CN';
		$lang = get_lang();

		if (is_array($GLOBALS['lang'][$locale])) {
			$lang = array_merge($lang, $GLOBALS['lang'][$locale]);
		}

		change_to_super();
		$this->_tpl->assign('lang', $lang);

		// AD-4：统一向所有视图注入 CSRF token，视图侧输出
		// <meta name="csrf-token"> 后，common/csrf.js 即可全局接管，
		// 无需逐个表单/逐个 $.post 改造。
		if (function_exists('csrf_token')) {
			$this->_tpl->assign('csrf_token', csrf_token());
		}

		try {
			return $this->_tpl->fetch($template);
		}
		catch (Exception $e) {
			$this->_tpl->template_dir = APPLICATON_ROOT . '/view/default';
			return $this->_tpl->fetch($template);
		}
	}

	/**
	 * 错误异常抛出
	 * @param string msg
	 */
	public function __control_exit($msg)
	{
		trigger_error($msg, E_USER_ERROR);
	}

	public function index()
	{
	}

	protected function out_error($errno = 500, $ret = false)
	{
		if ($ret['title'] == '') {
			$ret['title'] = '';
		}

		if ($ret['content'] == '') {
			$ret['content'] = '没有信息';
		}

		if ($ret['url'] == '') {
			$ret['url'] = '?c=user&a=info';
		}

		$this->_tpl->assign('title', $ret['title']);
		$this->_tpl->assign('content', $ret['content']);
		$this->_tpl->assign('url', $ret['url']);
		$this->_tpl->display('error.html');
	}

	protected function out_result($ret = array())
	{
		if ($ret['title'] == '') {
			$ret['title'] = '';
		}

		if ($ret['content'] == '') {
			$ret['content'] = '没有信息';
		}

		if ($ret['url'] == '') {
			$ret['url'] = '?c=user&a=info';
		}

		$this->_tpl->assign('title', $ret['title']);
		$this->_tpl->assign('content', $ret['content']);
		$this->_tpl->assign('url', $ret['url']);
		$this->_tpl->display('error.html');
	}
}


?>