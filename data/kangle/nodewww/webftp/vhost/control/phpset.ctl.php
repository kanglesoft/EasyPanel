<?php
needRole('vhost');
class PhpsetControl extends Control
{
	private $ini_dir = '/vhs/kangle/phpini';
	// 面板「PHP 扩展开关」落点根目录：kangle 容器内路径 = 宿主 ./data/kangle/etc/php-fpm-ini/。
	// add_php.sh 把其中【按版本划分的子目录】<phpXX>/ 以只读绑定挂载进对应 fpm 容器的
	// /usr/local/etc/php/conf.d/zz-panel（镜像 PHP_INI_SCAN_DIR 已含该子目录，且 PHP
	// 扫描不递归，故必须整版目录挂载而非共用一层）。写 enable-<ext>.ini = 开，删除 = 关，
	// 重启容器后 php -m 立即反映。开关为容器级（该 PHP 版本所有站点共享）。
	private $fpm_ini_dir = '/vhs/kangle/etc/php-fpm-ini';
	private $fpm_container_prefix = 'phpfpm-';
	// 面板所在 kangle 镜像内置 docker CLI（kangle/Dockerfile:122），配合 docker.sock 只读挂载
	private $docker_bin = '/usr/bin/docker';
	private $php_name;

	private $php_extension_dir = [
		'5.2' => '/lib/php/extensions/no-debug-non-zts-20060613',
		'5.3' => '/lib/php/extensions/no-debug-non-zts-20090626',
		'5.4' => '/lib/php/extensions/no-debug-non-zts-20100525',
		'5.5' => '/lib/php/extensions/no-debug-non-zts-20121212',
		'5.6' => '/lib/php/extensions/no-debug-non-zts-20131226',
		'7.0' => '/lib/php/extensions/no-debug-non-zts-20151012',
		'7.1' => '/lib/php/extensions/no-debug-non-zts-20160303',
		'7.2' => '/lib/php/extensions/no-debug-non-zts-20170718',
		'7.3' => '/lib/php/extensions/no-debug-non-zts-20180731',
		'7.4' => '/lib/php/extensions/no-debug-non-zts-20190902',
		'8.0' => '/lib/php/extensions/no-debug-non-zts-20200930',
		'8.1' => '/lib/php/extensions/no-debug-non-zts-20210902',
		'8.2' => '/lib/php/extensions/no-debug-non-zts-20220829',
		'8.3' => '/lib/php/extensions/no-debug-non-zts-20230831',
		'8.4' => '/lib/php/extensions/no-debug-non-zts-20240924',
	];

	// 本地 CGI（php74 内置）专用 Zend/加密加载器开关，走旧的 per-site ini 机制
	private $php_extensions = [
		'opcache' => [
			'name' => 'Opcache',
			'description' => '用于缓存并加速PHP脚本，开发模式请勿开启',
			'file' => 'opcache.so',
			'data' => "zend_extension={file}\nopcache.enable=1\nopcache.memory_consumption=128\nopcache.interned_strings_buffer=8\nopcache.max_accelerated_files=4000\nopcache.revalidate_freq=60\nopcache.fast_shutdown=1",
			'sort' => 2
			],
		'ioncube' => [
			'name' => 'ionCube Loader',
			'description' => '用于解密ionCube Encoder加密的PHP脚本',
			'file' => 'ioncube_loader',
			'data' => "zend_extension={file}",
			'sort' => 1
			],
		'sourceguardian' => [
			'name' => 'SourceGuardian',
			'description' => '用于解密SourceGuardian加密的PHP脚本',
			'file' => 'ixed',
			'data' => "extension={file}",
			'sort' => 6
			],
		'swooleloader2' => [
			'name' => 'Swoole Loader 2',
			'description' => '用于解密Swoole Compiler v2加密的PHP脚本',
			'file' => 'swoole_loader22',
			'data' => "extension={file}",
			'sort' => 4
			],
		'swooleloader3' => [
			'name' => 'Swoole Loader 3',
			'description' => '用于解密Swoole Compiler v3加密的PHP脚本',
			'file' => 'swoole_loader31',
			'data' => "extension={file}",
			'sort' => 5
			],
		'zend' => [
			'name' => 'ZendGuardLoader',
			'description' => '用于解密ZendGuard加密的PHP脚本',
			'file' => 'ZendGuardLoader',
			'data' => "zend_extension={file}",
			'sort' => 3
			],
	];

	// fpm 容器动态扩展的中文说明（未命中的扩展名回落通用说明）
	private $fpm_ext_hints = [
		'exif' => '读取 JPG/TIFF 等图片的 EXIF 元数据',
		'redis' => 'Redis 数据库客户端扩展',
		'memcached' => 'Memcached 客户端扩展',
		'soap' => 'SOAP Web Service 支持',
		'xdebug' => 'PHP 调试与分析扩展，生产环境请勿开启',
		'intl' => '国际化（ICU）支持',
		'zip' => 'ZIP 压缩包读写支持',
		'gd' => 'GD 图像库（验证码/缩略图常用）',
		'opcache' => 'PHP 脚本字节码缓存加速',
	];

	private $php_configs = [
		'display_errors' => [
			'description' => '是否输出详细错误信息',
			'type' => 'switch',
			'value' => 'Off'
		],
		'short_open_tag' => [
			'description' => '是否开启短标签',
			'type' => 'switch',
			'value' => 'On'
		],
		'max_execution_time' => [
			'description' => '脚本超时时间',
			'type' => 'number',
			'minlimit' => 10,
			'maxlimit' => 300,
			'value' => '30'
		],
		'default_socket_timeout' => [
			'description' => 'socket超时时间',
			'type' => 'number',
			'minlimit' => 10,
			'maxlimit' => 300,
			'value' => '60'
		],
		'memory_limit' => [
			'description' => 'PHP运行内存限制',
			'type' => 'number-M',
			'minlimit' => 16,
			'maxlimit' => 512,
			'value' => '128M'
		],
		'post_max_size' => [
			'description' => 'POST数据最大限制',
			'type' => 'number-M',
			'minlimit' => 10,
			'maxlimit' => 100,
			'value' => '100M'
		],
		'file_uploads' => [
			'description' => '是否允许上传文件',
			'type' => 'switch',
			'value' => 'On'
		],
		'upload_max_filesize' => [
			'description' => '上传文件最大限制',
			'type' => 'number-M',
			'minlimit' => 10,
			'maxlimit' => 100,
			'value' => '100M'
		],
		'max_file_uploads' => [
			'description' => '上传文件个数限制',
			'type' => 'number',
			'minlimit' => 1,
			'maxlimit' => 50,
			'value' => '20'
		],
	];

	public function __construct()
	{
		parent::__construct();
		if(!is_dir($this->ini_dir)) mkdir($this->ini_dir);

		$vhostinfo = apicall('vhostinfo', 'get2', array(getRole('vhost'), 'moduleversion', 101));
		$this->php_name = $vhostinfo['value'];
	}

	private function getCurrentExtensions($ini_data)
	{
		$phpver = substr($this->php_name,-2,1).'.'.substr($this->php_name,-1,1);
		if(!isset($this->php_extension_dir[$phpver])) return [];
		$extension_dir = '/vhs/kangle/ext/'.$this->php_name.$this->php_extension_dir[$phpver];
		if(!is_dir($extension_dir)) return [];

		$file_list = [];
		$files = scandir($extension_dir);
		foreach($files as $file){
            if($file == '.' || $file == '..') continue;
			$file_list[] = $file;
		}

		$list = [];
		foreach($this->php_extensions as $name=>$row){
			$file_name = null;
			foreach($file_list as $file){
				if(strpos($file, $row['file'])!==false){
					$file_name=$file;
					break;
				}
			}
			if($file_name){
				$row['file'] = $file_name;
				$row['data'] = str_replace('{file}', $file_name, $row['data']);
				$row['value'] = strpos($ini_data, $row['file'])!==false ? 1 : 0;
				$list[$name] = $row;
			}
		}
		return $list;
	}

	private function getCurrentConfigs($ini_data)
	{
		$templete_file = '/vhs/kangle/ext/'.$this->php_name.'/php-templete.ini';
		$templete_data = @file_get_contents($templete_file);
		if($templete_data === false) $templete_data = '';

		$list = [];
		foreach($this->php_configs as $name=>$row){
			// fpm 版本没有 php-templete.ini（页面自带默认值即基线），先兜底防止未定义索引
			$row['dvalue'] = $row['value'];
			if(strpos($templete_data, $name)!==false && preg_match("/$name = (.*)/", $templete_data, $param_val)){
				$row['dvalue'] = $param_val[1];
			}
			if(strpos($ini_data, $name)!==false && preg_match("/$name = (.*)/", $ini_data, $param_val)){
				$row['value'] = $param_val[1];
			}else{
				$row['value'] = $row['dvalue'];
			}
			if($row['type'] == 'switch'){
				$row['value'] = $row['value']=='On'?1:0;
				$row['dvalue'] = $row['dvalue']=='On'?1:0;
			}elseif($row['type'] == 'number'){
				$row['value'] = intval($row['value']);
				$row['dvalue'] = intval($row['dvalue']);
			}elseif($row['type'] == 'number-M'){
				$row['value'] = intval(substr($row['value'],0,-1));
				$row['dvalue'] = intval(substr($row['dvalue'],0,-1));
			}
			$list[$name] = $row;
		}
		return $list;
	}

	/* ────────────────────── fpm 容器扩展开关（挂载目录方案） ────────────────────── */

	/**
	 * 由 PHP 版本标识推导 fpm 容器名；非法/内置版本返回 null。
	 * 与 add_php.sh 的 container_name=phpfpm-<phpXX> 约定一致。
	 */
	private function containerNameFor($php_name)
	{
		if(!preg_match('/^php[0-9]+$/', (string)$php_name)){
			return null;
		}
		return $this->fpm_container_prefix.$php_name;
	}

	/**
	 * 当前站点 PHP 运行时是否为独立 fpm 容器（远程 fastcgi），
	 * 区别于主容器内置 php74（local cmd:，走旧 per-site ini 机制）。
	 */
	private function isFpmRuntime()
	{
		static $is = null;
		if($is !== null) return $is;

		$is = false;
		$container = $this->containerNameFor($this->php_name);
		if($container){
			$prefix = modcall('php', 'php_get_handler_prefix', array($this->php_name));
			$is = ($prefix === 'server:');
		}
		return $is;
	}

	/**
	 * 当前 PHP 版本的开关目录（与 add_php.sh 挂载的按版本子目录一致）。
	 * 仅在 isFpmRuntime() 为真时调用；php_name 已通过 ^php[0-9]+$ 校验。
	 */
	private function fpmIniDir()
	{
		return $this->fpm_ini_dir.'/'.$this->php_name;
	}

	/**
	 * 一次性探测容器内「已加载模块」与「扩展目录里的 .so」。
	 * 返回 ['loaded'=>[..], 'sos'=>[name=>path], 'ok'=>bool]；docker 不可用/容器未跑时 ok=false。
	 */
	private function probeFpmContainer($container)
	{
		$fail = ['loaded'=>[], 'sos'=>[], 'ok'=>false];
		if(!$container || !is_executable($this->docker_bin)) return $fail;

		$script = 'php -m 2>/dev/null | tr "\n" ","; echo "|"; '
			.'find /usr/local/lib/php/extensions -name "*.so" 2>/dev/null | tr "\n" ","';
		// timeout 兜底：docker daemon 异常时不拖死页面（容器内 /usr/bin/timeout 实测存在）
		$cmd = 'timeout 10 '.escapeshellarg($this->docker_bin).' exec '.escapeshellarg($container)
			.' sh -c '.escapeshellarg($script).' 2>/dev/null';
		exec($cmd, $out, $code);
		if($code !== 0) return $fail;

		$line = trim(implode('', (array)$out));
		$pos = strpos($line, '|');
		if($pos === false) return $fail;

		$loaded = [];
		foreach(explode(',', substr($line, 0, $pos)) as $m){
			$m = strtolower(trim($m));
			if($m !== '') $loaded[] = $m;
		}
		$sos = [];
		foreach(explode(',', substr($line, $pos + 1)) as $path){
			$path = trim($path);
			if($path === '' || strtolower(substr($path, -3)) !== '.so') continue;
			$name = strtolower(substr(basename($path), 0, -3));
			// 只接受安全扩展名，防止异常文件名注入后续路径拼接
			if(preg_match('/^[a-z0-9_]{1,32}$/', $name)) $sos[$name] = $path;
		}
		return ['loaded'=>$loaded, 'sos'=>$sos, 'ok'=>true];
	}

	/**
	 * fpm 容器面板可开关扩展列表（探测结果缓存 300s；写入开关后立即失效）。
	 * 候选规则：
	 *  - 容器内存在 <ext>.so 且当前未加载（= 镜像预装但出厂未启用，如 soap/redis）；
	 *  - 已被镜像级 ini 启用（php -m 已含，如 exif/intl）的不展示——面板无法关它，
	 *    展示了只会造成“开了个假开关”的错觉；
	 *  - 已存在面板 enable-<ext>.ini 的恒展示（保证能再次关掉，即使探测暂时失败）。
	 */
	private function getFpmExtensions()
	{
		$container = $this->containerNameFor($this->php_name);
		if(!$container) return [];

		$cache_file = '/tmp/ep_fpm_exts_'.$container.'.cache';
		$probe = null;
		if(file_exists($cache_file) && (time() - filemtime($cache_file)) < 300){
			$data = @json_decode(@file_get_contents($cache_file), true);
			if(is_array($data) && !empty($data['ok'])) $probe = $data;
		}
		if($probe === null){
			$probe = $this->probeFpmContainer($container);
			if($probe['ok']) @file_put_contents($cache_file, json_encode($probe));
		}

		// 面板已接管的扩展（enable 文件存在 = 开）
		$list = [];
		$panel_files = glob($this->fpmIniDir().'/enable-*.ini');
		if(is_array($panel_files)){
			foreach($panel_files as $f){
				if(preg_match('/enable-([a-z0-9_]{1,32})\.ini$/', $f, $m)){
					$list[$m[1]] = $this->fpmExtRow($m[1], 1);
				}
			}
		}

		// 镜像内可用但出厂未启用的 .so（探测成功才追加，探测失败时保守只显示已接管的）
		if(!empty($probe['ok'])){
			$loaded = array_flip($probe['loaded']);
			foreach($probe['sos'] as $name=>$path){
				if(isset($list[$name]) || isset($loaded[$name])) continue;
				$list[$name] = $this->fpmExtRow($name, 0);
			}
		}
		ksort($list);
		return $list;
	}

	private function fpmExtRow($name, $value)
	{
		$hint = isset($this->fpm_ext_hints[$name])
			? $this->fpm_ext_hints[$name]
			: $name.' 扩展（由 fpm 镜像提供）';
		$hint .= '。容器级开关，对该 PHP 版本的所有站点生效，保存后自动重启 fpm 容器';
		return [
			'name' => ucfirst($name),
			'description' => $hint,
			'file' => $name.'.so',
			'data' => 'extension='.$name.'.so',
			'value' => $value,
			'sort' => 99,
		];
	}

	private function fpmExtIniFile($name)
	{
		// $name 来源恒为服务端生成的候选键（^[a-z0-9_]{1,32}$），此处再兜底一次
		if(!preg_match('/^[a-z0-9_]{1,32}$/', $name)) return null;
		return $this->fpmIniDir().'/enable-'.$name.'.ini';
	}

	/**
	 * 应用扩展开关（写/删 enable-*.ini），返回是否发生实际变更。
	 */
	private function applyFpmExtChanges($pending_on, $pending_off)
	{
		$changed = false;
		if($pending_on || $pending_off){
			$dir = $this->fpmIniDir();
			if(!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)){
				exit('修改失败：无法创建扩展开关目录');
			}
		}
		foreach($pending_on as $name){
			$file = $this->fpmExtIniFile($name);
			if(!$file) continue;
			if(!file_exists($file)){
				if(@file_put_contents($file, "extension=".$name.".so\n") === false){
					exit('修改失败：无法写入 '.$file);
				}
				$changed = true;
			}
		}
		foreach($pending_off as $name){
			$file = $this->fpmExtIniFile($name);
			if(!$file) continue;
			if(file_exists($file)){
				if(!@unlink($file)){
					exit('修改失败：无法删除 '.$file);
				}
				$changed = true;
			}
		}
		if($changed){
			$container = $this->containerNameFor($this->php_name);
			@unlink('/tmp/ep_fpm_exts_'.$container.'.cache');
		}
		return $changed;
	}

	private function restartFpmContainer($container)
	{
		if(!$container || !is_executable($this->docker_bin)) return false;
		exec('timeout 30 '.escapeshellarg($this->docker_bin).' restart '.escapeshellarg($container).' 2>/dev/null', $out, $code);
		return $code === 0;
	}

	/* ───────────────────────────────── 页面与动作 ───────────────────────────────── */

	private function parse_php_ini()
	{
		$vhost = getRole('vhost');
		$ini_data = '';
		$ini_file = $this->ini_dir.'/php-'.$vhost.'.ini';
		if(file_exists($ini_file)){
			$ini_data = file_get_contents($ini_file);
		}
		$extlist = $this->getCurrentExtensions($ini_data);
		// fpm 运行时追加容器级动态扩展开关（本地 php74 场景 $extlist 仍为 Zend 加载器列表）
		if($this->isFpmRuntime()){
			foreach($this->getFpmExtensions() as $name=>$row){
				if(!isset($extlist[$name])) $extlist[$name] = $row;
			}
		}
		$configlist = $this->getCurrentConfigs($ini_data);
		return [$extlist, $configlist];
	}

	public function phpset()
	{
		$vhost = getRole('vhost');
		$versions = modcall('php', 'php_get_version');
		$version = 'PHP-'.substr($this->php_name,-2,1).'.'.substr($this->php_name,-1,1);

		list($extlist, $configlist) = $this->parse_php_ini();

		$this->_tpl->assign('versions', $versions);
		$this->_tpl->assign('version', $version);
		$this->_tpl->assign('extlist', $extlist);
		$this->_tpl->assign('configlist', $configlist);
		$this->_tpl->assign('is_fpm', $this->isFpmRuntime() ? 1 : 0);
		return $this->_tpl->fetch('phpset.html');
	}

	public function change()
	{
		$vhost = getRole('vhost');
		$versions = modcall('php', 'php_get_version');
		$v = trim($_REQUEST['v']);
		if(empty($v) || !array_key_exists($v, $versions))exit('参数错误');

		@unlink($this->ini_dir.'/php-'.$vhost.'.ini');

		// 远程 fpm 容器版本的 handler 前缀是 server:（与 modules/php/php.php php_call 同款推导），
		// 写死 cmd: 会把站点指到不存在的本地 CGI —— 接入 phpXX 后在后台切版本必须走这里。
		$prefix = modcall('php', 'php_get_handler_prefix', array($v));
		if($prefix !== 'server:' && $prefix !== 'cmd:') $prefix = 'cmd:';
		$arr['value'] = '1,' . $prefix . $v . ',*';

		if (!apicall('vhost', 'updateInfo', array($vhost, '1,php', $arr, 3))) {
			exit('修改失败');
		}

		if (!apicall('vhostinfo', 'set2', array($vhost, 'moduleversion', 101, $v))) {
			exit('修改失败');
		}
		exit('修改成功');
	}

	public function edit()
	{
		$ext = $_POST['ext'];
		$config = $_POST['config'];
		if($ext && !is_array($ext) || !is_array($config)) exit('参数不能为空');
		$vhost = getRole('vhost');
		$ini_file = $this->ini_dir.'/php-'.$vhost.'.ini';

		list($extlist, $configlist) = $this->parse_php_ini();
		$is_fpm = $this->isFpmRuntime();

		$pending_on = [];
		$pending_off = [];
		if($ext && !$is_fpm){
			uksort($ext, function($a, $b) use($extlist){
				$sa = isset($extlist[$a]['sort']) ? $extlist[$a]['sort'] : 0;
				$sb = isset($extlist[$b]['sort']) ? $extlist[$b]['sort'] : 0;
				if($sa > $sb) return 1;
				elseif($sa < $sb) return -1;
				else return 0;
			});
		}

		$save = false;
		$ini_data = '';
		if($ext){
			if($is_fpm){
				// fpm：开关 = 挂载目录内 enable-<ext>.ini 的写/删，容器级
				foreach($ext as $name=>$value){
					if(!array_key_exists($name, $extlist)) continue;
					$value = intval($value);
					if($value == 1){
						$pending_on[] = $name;
					}else{
						$pending_off[] = $name;
					}
				}
			}else{
				if($ext['swooleloader2']==1 && $ext['swooleloader3']==1) exit('Swoole Loader组件只能开启一个版本');
				if(($ext['swooleloader2']==1 || $ext['swooleloader3']==1) && $ext['ioncube']==1) exit('Swoole Loader与ionCube组件冲突');
				foreach($ext as $name=>$value){
					if(!array_key_exists($name, $extlist)) continue;
					$current = $extlist[$name];
					$value = intval($value);
					if($value == 1){
						$ini_data .= $current['data']."\n";
						$save = true;
					}
				}
			}
		}
		$ini_data .= "[PHP]\n";
		foreach($config as $name=>$value){
			if(!array_key_exists($name, $configlist)) continue;
			$current = $configlist[$name];
			$value = intval($value);
			if($value != $current['dvalue']){
				if(!getRole('admin')){
					if($current['minlimit'] && $value<$current['minlimit']) exit($name.' 不能小于 '.$current['minlimit']);
					if($current['maxlimit'] && $value>$current['maxlimit']) exit($name.' 不能大于 '.$current['maxlimit']);
				}
				if($current['type'] == 'switch'){
					$ini_data .= $name.' = '.($value==1?'On':'Off')."\n";
				}elseif($current['type'] == 'number'){
					$ini_data .= $name.' = '.$value."\n";
				}elseif($current['type'] == 'number-M'){
					$ini_data .= $name.' = '.$value.'M'."\n";
				}
				$save = true;
			}
		}

		if($save){
			file_put_contents($ini_file, $ini_data);
		}else{
			@unlink($ini_file);
		}

		// fpm 扩展开关落盘 + 容器重启（挂载文件删除在容器重启后即不再被扫描加载）
		$ext_changed = $is_fpm ? $this->applyFpmExtChanges($pending_on, $pending_off) : false;
		$container = $is_fpm ? $this->containerNameFor($this->php_name) : null;
		if($ext_changed){
			$restart_ok = $this->restartFpmContainer($container);
			if(function_exists('audit')){
				audit('docker', 'restart', $container, $restart_ok ? 'success' : 'error',
					array('reason' => 'php extension switch', 'vhost' => $vhost));
				audit('vhost', 'phpext', $vhost, $restart_ok ? 'success' : 'error',
					array('php' => $this->php_name, 'on' => $pending_on, 'off' => $pending_off));
			}
			if(!$restart_ok){
				exit('扩展开关已保存，但重启 PHP 容器 '.$container.' 失败，请手动重启该容器后生效');
			}
		}

		if($is_fpm){
			// fpm 站点无 per-vhost 本地 CGI 进程，开关生效依赖上面的容器重启；
			// rebootProcess 对 server: 版本结果无意义，不作为失败依据
			@apicall('vhost', 'rebootProcess', array($vhost));
			exit('修改成功');
		}

		$result = apicall('vhost', 'rebootProcess', array($vhost));
		if ($result) {
			exit('修改成功');
		}
		exit('修改失败');
	}

	public function reset()
	{
		$vhost = getRole('vhost');
		$ini_file = $this->ini_dir.'/php-'.$vhost.'.ini';
		@unlink($ini_file);

		// fpm 运行时同步重置：删除当前 PHP 版本开关目录内的全部 enable-*.ini。
		// 目录本身按版本划分（与 add_php.sh 挂载的 <phpXX>/ 子目录一致），
		// 因此不会误清其它 PHP 版本的开关；影响同版本其它站点，UI 已提示。
		if($this->isFpmRuntime()){
			$files = glob($this->fpmIniDir().'/enable-*.ini');
			$deleted = false;
			if(is_array($files)){
				foreach($files as $f){
					if(@unlink($f)) $deleted = true;
				}
			}
			if($deleted){
				$container = $this->containerNameFor($this->php_name);
				@unlink('/tmp/ep_fpm_exts_'.$container.'.cache');
				$ok = $this->restartFpmContainer($container);
				if(function_exists('audit')){
					audit('docker', 'restart', $container, $ok ? 'success' : 'error',
						array('reason' => 'php extension reset', 'vhost' => $vhost));
					audit('vhost', 'phpextreset', $vhost, $ok ? 'success' : 'error',
						array('php' => $this->php_name, 'files' => $files));
				}
				if(!$ok){
					exit('扩展开关文件已删除，但重启 PHP 容器 '.$container.' 失败，请手动重启后生效');
				}
			}
		}

		$result = apicall('vhost', 'rebootProcess', array($vhost));
		if ($result || $this->isFpmRuntime()) {
			exit('重置成功');
		}
		exit('重置失败');
	}

}

?>
