<?php
/**
 * geo.api.php —— 按地区封禁/放行（KGeoMark 链）能力探测与地区树数据源
 *
 * 背景（内核探测材料）：
 *   - /vhs/kangle/bin/kangle 编译进整套 KGeoMark 符号（nm 实证：
 *     _ZN8KGeoMark7getNameEv / load_data / geo_mark_download / add_flush_timer），
 *     getName() 返回 "geo"（反汇编 mov $0x853480 → 字符串 "geo"），故 XML 标签为
 *     <mark_geo>，属性集为 file / url / flush_time / name（editHtml 反汇编
 *     命中 0x8b7001='file'、0x8542ad='flush_time'、0x8b5b35='url'、0x8b8632='name'）。
 *   - supportRuntime() 返回 0（反汇编 xor %eax,%eax;ret），即 mark_geo **不支持
 *     运行时（WHM）编辑**，只能写在 config.xml 全局 request 链里、重启/recreate
 *     生效。因此面板侧绝不通过 WHM 修改全局链，只做「探测 + 展示」。
 *   - 内核自更新数据走 geo_mark_download（url 属性 + flush_time 定时），
 *     仓库内零 IP 地区库（find 无 qqwry/ipdb/geo 数据文件）——默认态必然是
 *     available:false, hint:'geo_mark_download'。
 *
 * 工作链路（与上游 easypanel .temp/repos/easypanel/framework/lib/geo.lib.php
 * 一致）：全局 request BEGIN 链放 <mark_geo file=... />，内核给命中请求打上
 * 地区标签（伪 header `_geo`，geo_lable 符号）；vhost 侧 !deny_geo / !allow_geo
 * 表用 acl_header(header='_geo') + deny/allow 实现按地区封禁/放行。
 *
 * 探测结果缓存于 setting 键 geo_probe_cache，格式
 *   "时间戳|kernel|chain|data|hint"，TTL 600s，避免每次页面渲染 grep 46MB
 *   ELF 并全量拉 WHM 链（照 http3.api.php 的成熟模式）。
 *
 * 假开关具体化钩子（供远端验收在真库就位前跑通开链路径）：
 *   setting 键 geo_force_enable='1' 时强制视为支持。与 http3 单元同款约定。
 */
class GeoAPI extends API
{
	/** 内核二进制路径（面板 php 与 kangle 同容器） */
	const BIN = '/vhs/kangle/bin/kangle';

	/** 探测缓存键，值 "ts:kernel:chain:data:hint" */
	const CACHE_KEY = 'geo_probe_cache';

	/** 强制显示钩子键（验收/演示用） */
	const FORCE_KEY = 'geo_force_enable';

	/** 探测缓存有效期（秒） */
	const CACHE_TTL = 600;

	/** vhost 侧地区黑名单表名（! 前缀 = kangle per-vhost access 表） */
	const DENY_TABLE = '!deny_geo';

	/** vhost 侧地区白名单表名（预留给 whitelist 单元复用） */
	const ALLOW_TABLE = '!allow_geo';

	/** mark_geo 打上的伪请求头名（上游 addBangeo 以 acl_header '_geo' 匹配） */
	const GEO_HEADER = '_geo';

	/**
	 * 当前环境地区封禁是否可用（bool 便捷入口，走缓存）
	 *
	 * @param bool $refresh true = 强制重新探测
	 * @return bool
	 */
	public function isSupported($refresh = false)
	{
		$status = $this->checkSource($refresh);
		return !empty($status['available']);
	}

	/**
	 * 数据源/能力探测（checkSource 接口的内核实现）
	 *
	 * 判定链：
	 *   force 钩子 → 缓存 → [kernel: 二进制含 KGeoMark 符号/字符串]
	 *                    && [chain: 全局 request BEGIN 已配 mark_geo]
	 *                    && [data: mark_geo file 属性指向的库文件存在]
	 *
	 * hint 语义：
	 *   'geo_mark_download'  内核在但 config.xml 无 mark_geo 链（或链无有效库），
	 *                        需要走内核 geo_mark_download(url,flush_time) 或人工
	 *                        放置库文件后增量 config.xml（交集成步）；
	 *   'kernel_missing'     二进制无 KGeoMark（非企业版/旧版内核）；
	 *   'data_missing'       链已配但 file 指向的库文件不存在；
	 *   ''                   available:true 时为空。
	 *
	 * @param bool $refresh true = 强制重新探测并刷新缓存
	 * @return array {available:bool,kernel:bool,chain:bool,data:bool,hint:string,geo_file:string,checked_at:int}
	 */
	public function checkSource($refresh = false)
	{
		$force = daocall('setting', 'get', array(self::FORCE_KEY));

		if ($force === '1' || $force === 1) {
			return array(
				'available' => true,
				'kernel' => true,
				'chain' => true,
				'data' => true,
				'hint' => 'geo_force_enable',
				'geo_file' => $this->defaultDataFile(),
				'checked_at' => time(),
			);
		}

		if (!$refresh) {
			$cached = $this->readCache();

			if ($cached !== false) {
				return $cached;
			}
		}

		$kernel = $this->checkKernel();
		$geo_mark = $kernel ? $this->findGlobalGeoMark() : false;
		$chain = ($geo_mark !== false);
		$geo_file = $chain ? $this->markDataFile($geo_mark) : $this->defaultDataFile();
		$data = $chain && $geo_file !== '' && is_file($geo_file) && filesize($geo_file) > 4096;

		$available = $kernel && $chain && $data;

		if ($available) {
			$hint = '';
		}
		elseif (!$kernel) {
			$hint = 'kernel_missing';
		}
		elseif (!$chain) {
			// 内核支持但全局链没配 mark_geo：缺的是“数据源挂链”，对应内核
			// 符号 geo_mark_download（url+flush_time 自拉库）。主验收路径。
			$hint = 'geo_mark_download';
		}
		else {
			$hint = 'data_missing';
		}

		$status = array(
			'available' => $available,
			'kernel' => $kernel,
			'chain' => $chain,
			'data' => $data,
			'hint' => $hint,
			'geo_file' => $geo_file,
			'checked_at' => time(),
		);

		$this->writeCache($status);
		return $status;
	}

	/**
	 * 给界面展示的中文提示（不可用时说明降级原因）
	 *
	 * @param array $status checkSource() 的结果
	 * @return string
	 */
	public function hintText($status)
	{
		if (!empty($status['available'])) {
			return '';
		}

		switch (isset($status['hint']) ? $status['hint'] : '') {
		case 'kernel_missing':
			return '当前 kangle 内核未编译 KGeoMark 地区模块，地区封禁不可用。';
		case 'geo_mark_download':
			return '全局请求控制链尚未挂载 mark_geo 数据源：需放置 IP 地区库并在 config.xml 增加 <mark_geo file=... url=... flush_time=.../> 链（内核经 geo_mark_download 自更新），由管理员完成后面板自动开放。';
		case 'data_missing':
			return 'mark_geo 已挂载但其指向的地区库文件不存在或过小：请检查 ' . (isset($status['geo_file']) ? $status['geo_file'] : '') . '。';
		default:
			return '地区数据库未就绪。';
		}
	}

	/**
	 * 地区树：大洲 => array(国家 => array(省份...))
	 *
	 * 内嵌自上游 .temp/repos/easypanel/style/geo-regions.json 的完整转写
	 * （2695 字节 JSON → PHP 字面量，仅中国大陆带省级列表）。放 PHP 常量而
	 * 非独立 json 文件，避免为只读数据再增共享资源文件。
	 *
	 * @return array
	 */
	public function regions()
	{
		return self::regionTree();
	}

	/**
	 * 校验三级选择并返回规范化路径（continent[,country[,province]]）
	 *
	 * 与上游 EpGeo::selection 同语义：国家为 '' 时省份必须为 ''（整洲），
	 * 省份为 '' 时到国家粒度。
	 *
	 * @param string $continent
	 * @param string $country
	 * @param string $province
	 * @return array|false 非法选择返回 false
	 */
	public function selection($continent, $country, $province)
	{
		$tree = self::regionTree();
		$continent = trim(strval($continent));
		$country = trim(strval($country));
		$province = trim(strval($province));

		if ($continent === '' || !isset($tree[$continent])) {
			return false;
		}

		if ($country === '') {
			return $province === '' ? array($continent) : false;
		}

		if (!isset($tree[$continent][$country])) {
			return false;
		}

		if ($province === '') {
			return array($continent, $country);
		}

		return in_array($province, $tree[$continent][$country], true) ? array($continent, $country, $province) : false;
	}

	/**
	 * 二进制 KGeoMark 特征探测
	 *
	 * grep -a 强制按文本处理 46MB ELF；-m 1 命中即停。exec 不可用/命令缺失
	 * 一律按“不支持”保守处理（功能整体隐藏，不给可点却无效的开关）。
	 * 特征串取符号名 KGeoMark（not stripped 二进制含 _ZN8KGeoMark 系列符号，
	 * 本仓 nm 实证），开源裁剪版不会命中。
	 *
	 * @return bool
	 */
	private function checkKernel()
	{
		if (!is_file(self::BIN)) {
			return false;
		}

		$out = array();
		$status = -1;
		$cmd = 'LC_ALL=C grep -a -m 1 -E ' . escapeshellarg('KGeoMark|geo_mark_download') . ' ' . escapeshellarg(self::BIN) . ' 2>/dev/null';
		@exec($cmd, $out, $status);
		return $status === 0;
	}

	/**
	 * 在全局 request 控制 BEGIN 链中查找 mark_geo 模型节点
	 *
	 * 兼容两种写法（同上游 EpGeo::hasGeo）：<mark_geo .../> 或
	 * <mark module='geo' .../>。找到返回该 WhmValue（可继续读 file 属性），
	 * 否则 false。WHM 不可达时 Access 返回 false，同样按未配置处理。
	 *
	 * @return mixed WhmValue|false
	 */
	private function findGlobalGeoMark()
	{
		load_lib('pub:access');

		if (!class_exists('Access')) {
			return false;
		}

		$access = new Access(null, 'request');
		$chains = $access->listChain('BEGIN', 1);

		if (!$chains || !is_object($chains)) {
			return false;
		}

		foreach ($chains->children() as $chain) {
			if (!is_object($chain)) {
				continue;
			}

			foreach ($chain->children() as $name => $model) {
				$model_name = $name;

				if (is_object($model) && method_exists($model, 'getName')) {
					$model_name = $model->getName();
				}

				if ($model_name === 'mark_geo') {
					return $model;
				}

				if ($model_name === 'mark' && is_object($model) && (string) $model['module'] === 'geo') {
					return $model;
				}
			}
		}

		return false;
	}

	/**
	 * 从 mark_geo 节点读取 file 属性
	 *
	 * @param mixed $mark WhmValue 节点
	 * @return string 无 file 属性时回落 defaultDataFile()
	 */
	private function markDataFile($mark)
	{
		if (is_object($mark)) {
			$file = (string) $mark['file'];

			if ($file !== '') {
				return $file;
			}
		}

		return $this->defaultDataFile();
	}

	/**
	 * 地区库缺省落盘位置（/vhs/kangle/etc 为面板既有机密/配置目录，
	 * shell.api.php changeEtcPermissions 保持其 700 权限）
	 *
	 * @return string
	 */
	private function defaultDataFile()
	{
		return '/vhs/kangle/etc/qqwry.dat';
	}

	/**
	 * 读探测缓存；缺失/过期/畸形返回 false
	 *
	 * @return array|false
	 */
	private function readCache()
	{
		$cached = daocall('setting', 'get', array(self::CACHE_KEY));

		if (!is_scalar($cached) || $cached === '') {
			return false;
		}

		$parts = explode(':', strval($cached));

		if (count($parts) != 5 || (time() - intval($parts[0])) >= self::CACHE_TTL) {
			return false;
		}

		$kernel = intval($parts[1]) === 1;
		$chain = intval($parts[2]) === 1;
		$data = intval($parts[3]) === 1;
		$available = $kernel && $chain && $data;

		if (!$available && $parts[4] === '' && $kernel) {
			// 缓存丢了 hint 细节时按判定链复原，保证降级文案不空转
			$parts[4] = $chain ? 'data_missing' : 'geo_mark_download';
		}

		$hint = str_replace('%3A', ':', $parts[4]);

		return array(
			'available' => $available,
			'kernel' => $kernel,
			'chain' => $chain,
			'data' => $data,
			'hint' => $hint,
			'geo_file' => $this->defaultDataFile(),
			'checked_at' => intval($parts[0]),
		);
	}

	/**
	 * 写探测缓存（失败静默，绝不影响主流程）
	 *
	 * hint 内不允许出现 ':'（缓存分隔符），当前所有 hint 值均为
	 * [a-z_]+，落库前再兜底替换一次。
	 *
	 * @param array $status
	 * @return void
	 */
	private function writeCache($status)
	{
		$hint = str_replace(':', '%3A', strval($status['hint']));
		daocall('setting', 'add', array(
			self::CACHE_KEY,
			time() . ':' . ($status['kernel'] ? '1' : '0') . ':' . ($status['chain'] ? '1' : '0') . ':' . ($status['data'] ? '1' : '0') . ':' . $hint,
		));
	}

	/**
	 * 地区树（源：上游 style/geo-regions.json 全量转写；省份仅中国大陆细分）
	 *
	 * @return array
	 */
	public static function regionTree()
	{
		return array(
			'亚洲' => array(
				'中国大陆' => array('北京', '上海', '天津', '重庆', '河北', '山西', '内蒙古', '辽宁', '吉林', '黑龙江', '江苏', '浙江', '安徽', '福建', '江西', '山东', '河南', '湖北', '湖南', '广东', '广西', '海南', '四川', '贵州', '云南', '西藏', '陕西', '甘肃', '青海', '宁夏', '新疆'),
				'中国香港' => array(),
				'中国台湾' => array(),
				'中国澳门' => array(),
				'不丹' => array(),
				'乌兹别克斯坦' => array(),
				'也门' => array(),
				'亚美尼亚' => array(),
				'以色列' => array(),
				'伊拉克' => array(),
				'伊朗' => array(),
				'卡塔尔' => array(),
				'印度' => array(),
				'印度尼西亚' => array(),
				'叙利亚' => array(),
				'吉尔吉斯斯坦' => array(),
				'哈萨克斯坦' => array(),
				'土库曼斯坦' => array(),
				'土耳其' => array(),
				'塔吉克斯坦' => array(),
				'孟加拉国' => array(),
				'尼泊尔' => array(),
				'巴勒斯坦' => array(),
				'巴基斯坦' => array(),
				'巴林' => array(),
				'文莱' => array(),
				'斯里兰卡' => array(),
				'新加坡' => array(),
				'日本' => array(),
				'朝鲜' => array(),
				'柬埔寨' => array(),
				'格鲁吉亚' => array(),
				'沙特阿拉伯' => array(),
				'泰国' => array(),
				'科威特' => array(),
				'科科斯（基林）群岛' => array(),
				'约旦' => array(),
				'缅甸' => array(),
				'老挝' => array(),
				'英属印度洋领地' => array(),
				'菲律宾' => array(),
				'蒙古' => array(),
				'越南' => array(),
				'阿塞拜疆' => array(),
				'阿富汗' => array(),
				'阿曼' => array(),
				'阿联酋' => array(),
				'韩国' => array(),
				'马尔代夫' => array(),
				'马来西亚' => array(),
				'黎巴嫩' => array(),
			),
			'欧洲' => array(
				'丹麦' => array(),
				'乌克兰' => array(),
				'俄罗斯' => array(),
				'保加利亚' => array(),
				'克罗地亚' => array(),
				'冰岛' => array(),
				'列支敦士登' => array(),
				'匈牙利' => array(),
				'北马其顿' => array(),
				'卢森堡' => array(),
				'圣马力诺' => array(),
				'塞尔维亚' => array(),
				'塞浦路斯' => array(),
				'奥兰' => array(),
				'奥地利' => array(),
				'安道尔' => array(),
				'希腊' => array(),
				'德国' => array(),
				'意大利' => array(),
				'拉脱维亚' => array(),
				'挪威' => array(),
				'捷克' => array(),
				'摩尔多瓦' => array(),
				'摩纳哥' => array(),
				'斯洛伐克' => array(),
				'斯洛文尼亚' => array(),
				'斯瓦尔巴和扬马延' => array(),
				'根西' => array(),
				'梵蒂冈' => array(),
				'比利时' => array(),
				'法国' => array(),
				'法罗群岛' => array(),
				'波兰' => array(),
				'波黑' => array(),
				'泽西' => array(),
				'爱尔兰' => array(),
				'爱沙尼亚' => array(),
				'瑞典' => array(),
				'瑞士' => array(),
				'白俄罗斯' => array(),
				'直布罗陀' => array(),
				'立陶宛' => array(),
				'罗马尼亚' => array(),
				'芬兰' => array(),
				'英国' => array(),
				'荷兰' => array(),
				'葡萄牙' => array(),
				'西班牙' => array(),
				'阿尔巴尼亚' => array(),
				'马恩岛' => array(),
				'马耳他' => array(),
				'黑山' => array(),
			),
			'非洲' => array(
				'中非' => array(),
				'乌干达' => array(),
				'乍得' => array(),
				'佛得角' => array(),
				'冈比亚' => array(),
				'几内亚' => array(),
				'几内亚比绍' => array(),
				'刚果共和国' => array(),
				'刚果民主共和国' => array(),
				'利比亚' => array(),
				'利比里亚' => array(),
				'加纳' => array(),
				'加蓬' => array(),
				'南苏丹' => array(),
				'南非' => array(),
				'博茨瓦纳' => array(),
				'卢旺达' => array(),
				'厄立特里亚' => array(),
				'吉布提' => array(),
				'喀麦隆' => array(),
				'圣多美和普林西比' => array(),
				'圣赫勒拿、阿森松和特里斯坦-达库尼亚' => array(),
				'坦桑尼亚' => array(),
				'埃及' => array(),
				'埃塞俄比亚' => array(),
				'塞内加尔' => array(),
				'塞拉利昂' => array(),
				'塞舌尔' => array(),
				'多哥' => array(),
				'安哥拉' => array(),
				'尼日利亚' => array(),
				'尼日尔' => array(),
				'布基纳法索' => array(),
				'布隆迪' => array(),
				'摩洛哥' => array(),
				'斯威士兰' => array(),
				'毛里塔尼亚' => array(),
				'毛里求斯' => array(),
				'津巴布韦' => array(),
				'留尼汪' => array(),
				'科摩罗' => array(),
				'科特迪瓦' => array(),
				'突尼斯' => array(),
				'索马里' => array(),
				'纳米比亚' => array(),
				'肯尼亚' => array(),
				'苏丹' => array(),
				'莫桑比克' => array(),
				'莱索托' => array(),
				'西撒哈拉地区' => array(),
				'贝宁' => array(),
				'赞比亚' => array(),
				'赤道几内亚' => array(),
				'阿尔及利亚' => array(),
				'马拉维' => array(),
				'马约特' => array(),
				'马达加斯加' => array(),
				'马里' => array(),
			),
			'南美洲' => array(
				'乌拉圭' => array(),
				'厄瓜多尔' => array(),
				'哥伦比亚' => array(),
				'圭亚那' => array(),
				'委内瑞拉' => array(),
				'巴拉圭' => array(),
				'巴西' => array(),
				'智利' => array(),
				'法属圭亚那' => array(),
				'玻利维亚' => array(),
				'福克兰群岛' => array(),
				'秘鲁' => array(),
				'苏里南' => array(),
				'阿根廷' => array(),
			),
			'北美洲' => array(
				'伯利兹' => array(),
				'加拿大' => array(),
				'危地马拉' => array(),
				'古巴' => array(),
				'哥斯达黎加' => array(),
				'圣卢西亚' => array(),
				'圣基茨和尼维斯' => array(),
				'圣巴泰勒米' => array(),
				'圣文森特和格林纳丁斯' => array(),
				'圣皮埃尔和密克隆' => array(),
				'墨西哥' => array(),
				'多米尼克' => array(),
				'多米尼加' => array(),
				'安圭拉' => array(),
				'安提瓜和巴布达' => array(),
				'尼加拉瓜' => array(),
				'巴哈马' => array(),
				'巴巴多斯' => array(),
				'巴拿马' => array(),
				'库拉索' => array(),
				'开曼群岛' => array(),
				'格林纳达' => array(),
				'格陵兰' => array(),
				'法属圣马丁' => array(),
				'波多黎各' => array(),
				'洪都拉斯' => array(),
				'海地' => array(),
				'牙买加' => array(),
				'特克斯和凯科斯群岛' => array(),
				'特立尼达和多巴哥' => array(),
				'瓜德罗普' => array(),
				'百慕大' => array(),
				'美国' => array(),
				'美国本土外小岛屿' => array(),
				'美属维尔京群岛' => array(),
				'英属维尔京群岛' => array(),
				'荷兰加勒比区' => array(),
				'荷属圣马丁' => array(),
				'萨尔瓦多' => array(),
				'蒙特塞拉特' => array(),
				'阿鲁巴' => array(),
				'马提尼克' => array(),
			),
			'大洋洲' => array(
				'东帝汶' => array(),
				'关岛' => array(),
				'北马里亚纳群岛' => array(),
				'图瓦卢' => array(),
				'圣诞岛' => array(),
				'基里巴斯' => array(),
				'密克罗尼西亚联邦' => array(),
				'巴布亚新几内亚' => array(),
				'帕劳' => array(),
				'库克群岛' => array(),
				'所罗门群岛' => array(),
				'托克劳' => array(),
				'斐济' => array(),
				'新喀里多尼亚' => array(),
				'新西兰' => array(),
				'汤加' => array(),
				'法属波利尼西亚' => array(),
				'澳大利亚' => array(),
				'瑙鲁' => array(),
				'瓦利斯和富图纳' => array(),
				'瓦努阿图' => array(),
				'皮特凯恩群岛' => array(),
				'纽埃' => array(),
				'美属萨摩亚' => array(),
				'萨摩亚' => array(),
				'诺福克岛' => array(),
				'马绍尔群岛' => array(),
			),
			'南极洲' => array(
				'南乔治亚和南桑威奇群岛' => array(),
				'南极洲' => array(),
				'布韦岛' => array(),
				'法属南部和南极领地' => array(),
				'赫德岛和麦克唐纳群岛' => array(),
			),
			'搜索引擎' => array(
				'360' => array(),
				'百度' => array(),
				'必应' => array(),
				'谷歌' => array(),
			),
		);
	}
}

?>
