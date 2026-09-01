<?php
needRole('vhost');
define('BEGIN', 'BEGIN');
define('TABLENAME', '!anticc');
define('ACTION', 'table:!anticc');
class AnticcControl extends Control
{
	private $access;

	public function __construct()
	{
		parent::__construct();
		load_lib('pub:access');
		$this->access = new Access(getRole('vhost'));
	}

	public function anticcFrom()
	{
		$user = daocall('vhost', 'getVhost', array(getRole('vhost')));

		$this->anticcAddTable();

		if ($this->access->findChain(BEGIN, TABLENAME)) {
			$this->_tpl->assign('at', 1);
		}

		$result = $this->access->listChain(TABLENAME);

		$white_urls = [];
		if ($result) {
			foreach ($result->children() as $chain) {
				foreach ($chain->children() as $name=>$ch) {
					if($name == 'mark_anti_cc'){
						$msg = file_get_contents($user['doc_root'] . '/access.xml');
						preg_match("/<html id=\'anticc_(.*?)\'>/", $msg, $match);
						$mode = $match[1];
						$cc = array('request' => (string) $ch['request'], 'second' => (string) $ch['second'], 'wl' => (string) $ch['wl'], 'flush' => (string) $ch['flush'], 'fix_url' => (string) $ch['fix_url'], 'skip_cache' => (string) $ch['skip_cache'], 'mode' => $mode);

						$this->_tpl->assign('cc', $cc);
					}elseif($name == 'acl_srcs'){
						$this->_tpl->assign('whiteip', str_replace('|',"\r\n",$ch));
					}elseif($name == 'acl_file_ext'){
						$this->_tpl->assign('whiteext', $ch);
					}elseif($name == 'acl_url'){
						$white_urls[] = $ch;
					}
				}
			}
		}

		$mode_list = $this->anticc_mode();
		$modes = [];
		foreach($mode_list as $key => $item){
			if($item['show']){
				$modes[$key] = $item['name'];
			}
		}
		$this->_tpl->assign('modes', $modes);
		$this->_tpl->assign('whiteurl', implode("\r\n", $white_urls));

		return $this->_tpl->fetch('anticc/anticcfrom.html');
	}

	private function anticc_mode(){
		$mode_list = [
			'redirect' => [
				'name' => '普通跳转模式',
				'html' => "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nConnection: keep-alive\r\nCache-Control: no-cache,no-store\r\n\r\n<html id='anticc_redirect'><body><script language='javascript'>{{revert:cbk_var}};cbk_defender_{{session_key}}=cbk_var;cbk_var='';window.location=cbk_defender_{{session_key}};</script></body></html>",
				'show' => true
			],
			'timeout' => [
				'name' => '延时跳转模式',
				'html' => "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nConnection: keep-alive\r\nCache-Control: no-cache,no-store\r\n\r\n<!DOCTYPE html><html id='anticc_timeout'><head><title></title><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no\"><meta name=\"renderer\" content=\"webkit\"><meta http-equiv=\"x-ua-compatible\" content=\"IE=edge,chrome=1\"><style>body{font-family:-apple-system,'Microsoft YaHei',sans-serif;background:#f5f7fa;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center}.box{background:#fff;padding:32px 40px;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.08);text-align:center}.logo{font-size:40px;margin-bottom:8px}.tip{color:#666;font-size:14px;line-height:1.8}.progress{height:8px;background:#eee;border-radius:4px;margin-top:18px;overflow:hidden}.progress-bar{height:100%;width:0;background:#3b82f6;transition:width .4s}</style></head><body><div class=\"box\"><div class=\"logo\"><div style='font-size:40px'>🛡️</div></div><div class=\"tip\"><small><div>当前网站访问人数较多</div><div>系统正在自动为您分配最快的服务器</div></small></div><div class=\"progress\"><div id=\"progress-bar\" class=\"progress-bar progress-bar-success\" role=\"progressbar\" aria-valuemin=\"0\" aria-valuemax=\"100\" style=\"width:0%\"></div></div></div><script>{{revert:caihong_defender_tmp}};caihong_defender_{{session_key}}=caihong_defender_tmp;caihong_defender_tmp='';function progress(p){document.getElementById(\"progress-bar\").style.width=p+\"%\"}setTimeout(function(){progress(\"5\");setTimeout(function(){progress(\"60\");setTimeout(function(){progress(\"95\");window.location.href=caihong_defender_{{session_key}};},500);},500);},300);</script></body></html>",
				'show' => true
			],
			'click' => [
				'name' => '点击验证模式',
				'html' => "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nConnection: keep-alive\r\nCache-Control: no-cache,no-store\r\n\r\n<!DOCTYPE html><html id='anticc_click'><head><meta charset='utf-8'><title>请进行安全验证</title><meta name='viewport' content='width=device-width,initial-scale=1'><style>body{font-family:-apple-system,BlinkMacSystemFont,'Microsoft YaHei',sans-serif;background:#f5f7fa;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center}.box{background:#fff;padding:32px 36px;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.08);text-align:center;max-width:400px}.logo{font-size:40px;margin-bottom:10px}.hd{color:#333;font-size:15px;line-height:1.8;margin-bottom:22px}.btn{padding:10px 28px;background:#3b82f6;color:#fff;border:0;border-radius:6px;font-size:15px;cursor:pointer;font-family:inherit}.btn:hover{background:#2563eb}.btn:disabled{background:#9ca3af;cursor:default}.tip{color:#999;font-size:12px;margin-top:14px;min-height:16px}</style></head><body><div class='box'><div class='logo'>&#128737;</div><div class='hd'>很抱歉，当前访问人数过多，请完成<strong>安全验证</strong>后继续访问</div><button type='button' id='anticc_btn' class='btn'>点击验证并继续</button><div class='tip' id='anticc_tip'></div></div><script>{{revert:cbk_var}}cbk_defender_{{session_key}}=cbk_var;cbk_var='';(function(){var b=document.getElementById('anticc_btn');b.addEventListener('click',function(){b.disabled=true;b.innerHTML='验证成功，正在跳转...';document.getElementById('anticc_tip').innerHTML='若长时间未跳转，请刷新页面重试';location.href=cbk_defender_{{session_key}};});})();</script></body></html>",
				'show' => true
			],
			'slideverify' => [
				'name' => '滑动验证码模式',
				'html' => "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nConnection: keep-alive\r\nCache-Control: no-cache,no-store\r\n\r\n<!DOCTYPE html><html id='anticc_slideverify'><head><meta charset='utf-8'><title>请进行安全验证</title><meta name='viewport' content='width=device-width,initial-scale=1,user-scalable=no'><style>body{font-family:-apple-system,BlinkMacSystemFont,'Microsoft YaHei',sans-serif;background:#f5f7fa;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center}.box{background:#fff;padding:32px 36px;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.08);text-align:center;max-width:400px}.logo{font-size:40px;margin-bottom:10px}.hd{color:#333;font-size:15px;line-height:1.8;margin-bottom:22px}.btn{padding:10px 28px;background:#3b82f6;color:#fff;border:0;border-radius:6px;font-size:15px;cursor:pointer;font-family:inherit}.btn:hover{background:#2563eb}.btn:disabled{background:#9ca3af;cursor:default}.tip{color:#999;font-size:12px;margin-top:14px;min-height:16px}.track{position:relative;width:280px;height:42px;background:#eef2f7;border-radius:6px;margin:0 auto;overflow:hidden;-webkit-user-select:none;user-select:none}.fill{position:absolute;left:0;top:0;height:100%;width:0;background:#bfdbfe}.label{position:absolute;left:0;top:0;width:100%;height:42px;line-height:42px;text-align:center;color:#8a94a6;font-size:13px}.handle{position:absolute;left:0;top:0;width:42px;height:42px;background:#fff;box-shadow:0 1px 5px rgba(0,0,0,.18);border-radius:6px;cursor:pointer;line-height:42px;text-align:center;color:#3b82f6;font-size:20px;font-weight:700}.ok .fill{background:#bbf7d0}.ok .handle{color:#16a34a}</style></head><body><div class='box'><div class='logo'>&#128737;</div><div class='hd'>网站当前访问量较大，请拖动滑块完成验证后继续访问</div><div class='track' id='sv_track'><div class='fill' id='sv_fill'></div><div class='label' id='sv_label'>请按住滑块拖到最右侧</div><div class='handle' id='sv_handle'>&raquo;</div></div></div><script>{{revert:caihong_defender_tmp}};caihong_defender__{{session_key}}=caihong_defender_tmp;caihong_defender_tmp='';(function(){var t=document.getElementById('sv_track'),h=document.getElementById('sv_handle'),f=document.getElementById('sv_fill'),l=document.getElementById('sv_label'),dragging=false,off=0,done=false;function px(e){return (e.touches&&e.touches.length)?e.touches[0].clientX:e.clientX;}function mx(){return t.offsetWidth-h.offsetWidth;}function start(e){if(done)return;dragging=true;off=px(e)-h.offsetLeft;}function move(e){if(!dragging||done)return;if(e.cancelable)e.preventDefault();var x=px(e)-off,m=mx();if(x<0)x=0;if(x>m)x=m;h.style.left=x+'px';f.style.width=(x+h.offsetWidth)+'px';if(x>=m-1){done=true;dragging=false;t.className='track ok';h.innerHTML='&#10003;';l.innerHTML='验证通过，正在跳转...';location.href=caihong_defender__{{session_key}};}}function end(){if(done||!dragging)return;dragging=false;h.style.left='0px';f.style.width='0px';}h.addEventListener('mousedown',start);h.addEventListener('touchstart',start);document.addEventListener('mousemove',move);document.addEventListener('touchmove',move,{passive:false});document.addEventListener('mouseup',end);document.addEventListener('touchend',end);})();</script></body></html>",
				'show' => true
			],
			'captcha' => [
				'name' => '算术验证模式',
				'html' => "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nConnection: keep-alive\r\nCache-Control: no-cache,no-store\r\n\r\n<!DOCTYPE html><html id='anticc_captcha'><head><meta charset='utf-8'><title>请进行安全验证</title><meta name='viewport' content='width=device-width,initial-scale=1,user-scalable=no'><style>body{font-family:-apple-system,BlinkMacSystemFont,'Microsoft YaHei',sans-serif;background:#f5f7fa;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center}.box{background:#fff;padding:32px 36px;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.08);text-align:center;max-width:400px}.logo{font-size:40px;margin-bottom:10px}.hd{color:#333;font-size:15px;line-height:1.8;margin-bottom:22px}.btn{padding:10px 28px;background:#3b82f6;color:#fff;border:0;border-radius:6px;font-size:15px;cursor:pointer;font-family:inherit}.btn:hover{background:#2563eb}.btn:disabled{background:#9ca3af;cursor:default}.tip{color:#999;font-size:12px;margin-top:14px;min-height:16px}.q{font-size:22px;color:#111;font-weight:600;letter-spacing:2px;margin-bottom:16px}.ipt{width:120px;height:34px;border:1px solid #d1d5db;border-radius:6px;text-align:center;font-size:16px;outline:0;font-family:inherit;vertical-align:middle}.ipt:focus{border-color:#3b82f6}</style></head><body><div class='box'><div class='logo'>&#128737;</div><div class='hd'>很抱歉，当前访问人数过多，请回答下面的问题后继续访问</div><div class='q' id='ac_q'></div><div><input type='text' id='ac_ans' class='ipt' inputmode='numeric' autocomplete='off' placeholder='请输入答案'> <button type='button' id='ac_ok' class='btn'>验证</button></div><div class='tip' id='ac_msg'></div></div><script>{{revert:cbk_var}}cbk_defender_{{session_key}}=cbk_var;cbk_var='';(function(){var a=Math.floor(Math.random()*8)+2,b=Math.floor(Math.random()*8)+2,ans=a+b;document.getElementById('ac_q').innerHTML=a+' + '+b+' = ?';var i=document.getElementById('ac_ans'),k=document.getElementById('ac_ok'),m=document.getElementById('ac_msg');function go(){if(parseInt(i.value,10)===ans){m.style.color='#16a34a';m.innerHTML='验证通过，正在跳转...';k.disabled=true;location.href=cbk_defender_{{session_key}};}else{m.style.color='#dc2626';m.innerHTML='答案不正确，请重试';i.value='';i.focus();}}k.addEventListener('click',go);i.addEventListener('keydown',function(e){if(e.keyCode===13){e.preventDefault();go();}});try{i.focus();}catch(err){}})();</script></body></html>",
				'show' => true
			],
			'vcode' => [
				'name' => '图形验证码模式',
				'html' => "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nConnection: keep-alive\r\nCache-Control: no-cache,no-store\r\n\r\n<!DOCTYPE html><html id='anticc_vcode'><head><title>访问验证</title><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><style>body,html{width:100%;height:100%}body{display:flex;justify-content:center;align-items:center;margin:0;padding:0;text-align:center;font-family:\"微软雅黑\",Arial,Helvetica,sans-serif;font-size:14px;background-color:#f9f9f9;color:#666}div,dl,dt,h1,h2,input,li,p,ul{margin:0;padding:0}h1{font-size:25px;text-align:left;line-height:40px;margin-bottom:10px;color:#666}.main{width:456px}.warncontenter{height:280px;background-color:#fff;margin:30px 0;padding-top:30px}.captcha-text{width:112px;height:34px;margin:23px 0 0 36px;font-size:19px;color:#111;line-height:26px;font-weight:600}.capture-img{float:left;margin-left:20px;width:200px}.capture-img span{margin-left:10px;line-height:40px}.capture-img span img{width:160px}.warnbtn{margin:40px auto}.code-btn{width:174px;height:30px;line-height:30px;background:#008cff;color:#fff;border-radius:59.5px;font-weight:700;text-align:center;border:0;cursor:pointer;margin-right:40px;margin-top:20px}.code-input{width:180px;height:32px;margin-right:36px;border:1px solid #ccc;border-left:none;border-right:none;border-top:none;padding:0 0 0 10px;outline:0}.visit-ip{text-align:left;margin-left:45px}</style></head><body><div class=\"main\"><div class=\"warncontenter\"><div class=\"captcha-text\">验证码访问</div><div class=\"visit-ip\">当前网站访问量过大，请输入验证码继续浏览</div><div class=\"form\" style=\"margin-top:40px\"><form action=\"/KANGLE_CCIMG.php\" method=\"GET\"><input name='k' value='{{session_key}}' type='hidden'><div class=\"captcha\"><div class=\"capture-img\"><span><img src=\"/KANGLE_CCIMG.php?k={{session_key}}\" alt=\"验证码\"></span></div><div class=\"capture-input\"><input type=\"text\" name=\"v\" class=\"code-input\" placeholder=\"输入验证码\" autocomplete=\"off\"><input type=\"submit\" value=\"确定\" class=\"code-btn\"></div></div></form></div></div></div></body></html>",
				'show' => true
			],
		];
		if(!file_exists('/var/run/cdnbest.pid')){
			unset($mode_list['vcode']);
		}
		return $mode_list;
	}

	public function anticcAdd()
	{
		$check_result = apicall('access', 'checkAccess', array('ent'));

		if ($check_result !== true) {
			exit($check_result);
		}

		$mode = trim($_REQUEST['mode']);
		$mode_list = $this->anticc_mode();
		if(!array_key_exists($mode, $mode_list))exit('未知防护模式');
		$msg = $mode_list[$mode]['html'];

		$this->access->delChainByName(TABLENAME, TABLENAME);

		$request = intval($_REQUEST['request']);
		$second = intval($_REQUEST['second']);
		$whiteip = $_REQUEST['whiteip'];
		$whiteurl = $_REQUEST['whiteurl'];
		$whiteext = $_REQUEST['whiteext'];
		if(!empty($whiteip)){
			$whiteip = str_replace(array("\r\n", "\r", "\n"), "|", $whiteip);
			$arrs = explode("|",$whiteip);
			$ipdata = '';
			foreach($arrs as $ip){
				$ip = trim($ip);
				if(empty($ip) || !strpos($ip,'.'))continue;
				$ipdata .= $ip . '|';
			}
			$ipdata = trim($ipdata, '|');
		}

		$wl = 1;
		$fix_url = 1;
		$skip_cache = 1;
		$arr['action'] = 'continue';
		$arr['name'] = TABLENAME;
		if(!empty($ipdata)){
			$modeles['acl_srcs'] = array('revers' => 1, 'split' => '|', 'v' => $ipdata);
		}
		if(!empty($whiteurl)){
			$whiteurl = str_replace(array("\r\n", "\r", "\n"), "[br]", $whiteurl);
			$arrs = explode("[br]",$whiteurl);
			$i=0;
			foreach($arrs as $url){
				$url = trim($url);
				if(empty($url))continue;
				$modeles['acl_url#'.$i++] = array('revers' => 1, 'nc' => 1, 'url' => $url);
			}
		}
		if(!empty($whiteext)){
			$modeles['acl_file_ext'] = array('revers' => 1, 'icase' => 1, 'split' => '|', 'v' => $whiteext);
		}
		$modeles['mark_anti_cc'] = array('request' => $request, 'second' => $second, 'wl' => $wl, 'fix_url' => $fix_url, 'skip_cache' => $skip_cache, 'msg' => $msg);
		$result = $this->access->addChain(TABLENAME, $arr, $modeles);

		if (!$result) {
			exit('保存设置失败');
		}

		apicall('vhost', 'updateVhostSyncseq', array(getRole('vhost')));
		exit('成功');
	}

	/**
	 * 开关
	 * Enter description here ...
	 */
	public function anticcCheckOn()
	{
		$status = intval($_REQUEST['status']);

		switch ($status) {
		case '2':
			$this->access->delChainByName(BEGIN, TABLENAME);
			break;

		case '1':
			$arr = array('action' => ACTION, 'name' => TABLENAME);
			$this->access->addChain(BEGIN, $arr);
			break;

		default:
			break;
		}

		apicall('vhost', 'updateVhostSyncseq', array(getRole('vhost')));
		exit('成功');
	}

	public function anticcDel()
	{
		if ($this->access->delChainByName(TABLENAME, TABLENAME)) {
			apicall('vhost', 'updateVhostSyncseq', array(getRole('vhost')));
			exit('成功');
		}

		exit('删除失败');
	}

	private function anticcAddChain()
	{
		if ($this->access->findChain(BEGIN, TABLENAME)) {
			return true;
		}

		$arr = array('action' => ACTION, 'name' => TABLENAME);
		$this->access->addChain(BEGIN, $arr);
	}

	/**
	 * 创建表
	 * Enter description here ...
	 */
	private function anticcAddTable()
	{
		$tables = $this->access->listTable();
		$table_finded = false;

		foreach ($tables as $table) {
			if ($table == TABLENAME) {
				$table_finded = true;
				break;
			}
		}

		if (!$table_finded) {
			if (!$this->access->addTable(TABLENAME)) {
				return $this->show_msg('不能增加表');
			}
		}
	}

	private function show_msg($msg)
	{
		$this->_tpl->assign('msg', $msg);
		return $this->_tpl->fetch('msg.html');
	}
}

?>