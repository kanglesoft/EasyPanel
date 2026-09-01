<?php
/**
 * DockerApi — 容器管理（列表 / 启动 / 停止）
 *
 * 仅面向“业务容器”：通过 label 过滤掉基础设施容器（kangle / mysql / php-fpm 等，
 * 均带 com.kangle.role=infra），并额外排除面板自身容器，避免误操作导致面板失联。
 *
 * 前置：kangle 容器需挂载宿主 /var/run/docker.sock（只读即可），且镜像内已安装
 * docker CLI（见 kangle/Dockerfile）。所有命令经 docker CLI 直连宿主 Docker 守护进程。
 */
class DockerApi extends API
{
	private $docker_bin = '/usr/bin/docker';
	private $infra_label = 'com.kangle.role=infra';

	/**
	 * 取得当前面板所在容器 ID（用于二次排除自身）。
	 * 优先从 /proc/self/cgroup 解析，回退到 /proc/self/mountinfo。
	 */
	private function selfId()
	{
		$id = '';
		if (is_readable('/proc/self/cgroup')) {
			$c = @file_get_contents('/proc/self/cgroup');
			if ($c && preg_match('/docker[\/\-]([0-9a-f]{64})/', $c, $m)) {
				$id = $m[1];
			}
		}
		if ($id === '' && is_readable('/proc/self/mountinfo')) {
			$c = @file_get_contents('/proc/self/mountinfo');
			if ($c && preg_match('/[0-9a-f]{64}/', $c, $m)) {
				$id = $m[0];
			}
		}
		return $id;
	}

	/**
	 * 执行 docker CLI，返回 ['code'=>int, 'output'=>string]
	 */
	private function run($args)
	{
		$cmd = $this->docker_bin . ' ' . $args . ' 2>&1';
		exec($cmd, $out, $code);
		return array('code' => (int)$code, 'output' => implode("\n", $out));
	}

	private function clean($s)
	{
		return trim(preg_replace('/\s+/', ' ', (string)$s));
	}

	/**
	 * 列出容器（已过滤基础设施容器与面板自身）。
	 */
	public function listContainers()
	{
		$self = $this->selfId();
		// 注意：Docker CLI 的 --filter 不接受 JSON 过滤串（如 {"label":["!x"]}），
		// 且 label 值含 "=" 时（com.kangle.role=infra）CLI 原生否定语法
		// label!=com.kangle.role=infra 会被按首个 "=" 切错而报 invalid filter。
		// 因此这里去掉 --filter，直接列出全部容器后在 PHP 侧按 label 排除基础设施容器，
		// 该做法跨 Docker 版本均可靠。
		$format = escapeshellarg('{{.ID}}\t{{.Names}}\t{{.Image}}\t{{.Status}}\t{{.State}}\t{{.CreatedAt}}\t{{.Labels}}');
		$r = $this->run('ps -a --format ' . $format);

		if ($r['code'] !== 0) {
			return array('success' => false, 'msg' => '获取容器列表失败：' . $this->clean($r['output']));
		}

		$list = array();
		foreach (explode("\n", $r['output']) as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			$f = explode("\t", $line);
			if (count($f) < 6) {
				continue;
			}
			$id = $f[0];
			// 排除基础设施容器（带 com.kangle.role=infra 标签）
			$labels = isset($f[6]) ? $f[6] : '';
			if (stripos($labels, $this->infra_label) !== false) {
				continue;
			}
			if ($self !== '' && strpos($id, $self) === 0) {
				continue;
			}
			$list[] = array(
				'id' => $id,
				'name' => $f[1],
				'image' => $f[2],
				'status' => $f[3],
				'state' => $f[4],
				'created' => isset($f[5]) ? $f[5] : '',
			);
		}
		return array('success' => true, 'list' => $list);
	}

	public function startContainer($id)
	{
		return $this->opContainer($id, 'start');
	}

	public function stopContainer($id)
	{
		return $this->opContainer($id, 'stop');
	}

	/**
	 * 重启容器（2-B）
	 *
	 * 复用 opContainer()，因此自动继承三重防护（标识白名单、禁操作自身、
	 * inspect 复查 infra label），不存在「新增动作绕过防护」的风险。
	 */
	public function restartContainer($id)
	{
		return $this->opContainer($id, 'restart');
	}

	/**
	 * 启动 / 停止 / 重启容器的统一出口：执行 + 审计。
	 *
	 * 把审计收在这里而不是分散到三个 public 方法，是为了杜绝
	 * 「新增一个动作却忘记写审计」——本类所有容器操作都必须经过它。
	 */
	private function opContainer($id, $op)
	{
		$id = trim((string)$id);
		$res = $this->doOpContainer($id, $op);

		// 2-C 审计：宿主级操作必须留痕。失败同样要记，
		// 否则事后无法区分「管理员没操作过」与「操作了但被拒绝/失败」。
		if (function_exists('audit')) {
			audit(
				'docker',
				$op,
				$id === '' ? '-' : $id,
				$res['success'] ? 'success' : 'error',
				array('msg' => isset($res['msg']) ? $res['msg'] : '')
			);
		}

		return $res;
	}

	/**
	 * 启动 / 停止 / 重启容器，含多重防护：非法标识、操作自身、操作基础设施容器均拒绝。
	 */
	private function doOpContainer($id, $op)
	{
		$id = trim((string)$id);
		if ($id === '' || !(preg_match('/^[0-9a-f]{1,64}$/i', $id) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{1,127}$/', $id))) {
			return array('success' => false, 'msg' => '非法的容器标识');
		}

		$self = $this->selfId();
		if ($self !== '' && strpos($id, $self) === 0) {
			return array('success' => false, 'msg' => '禁止操作面板自身容器');
		}

		// 二次防护：带 infra 标签的容器不允许被操作
		$inspect = $this->run('inspect -f ' . escapeshellarg('{{json .Config.Labels}}') . ' ' . escapeshellarg($id));
		if ($inspect['code'] !== 0) {
			return array('success' => false, 'msg' => '容器不存在或无法访问：' . $this->clean($inspect['output']));
		}
		if (stripos($inspect['output'], $this->infra_label) !== false) {
			return array('success' => false, 'msg' => '该容器属于基础设施，禁止操作');
		}

		$res = $this->run($op . ' ' . escapeshellarg($id));
		if ($res['code'] !== 0) {
			return array('success' => false, 'msg' => '操作失败：' . $this->clean($res['output']));
		}

		// 三种动作的中文提示：用显式映射而非三元表达式，
		// 新增动作时这里会返回带原名兜底，不会静默显示错动词。
		$verbs = array('start' => '启动', 'stop' => '停止', 'restart' => '重启');
		$verb = isset($verbs[$op]) ? $verbs[$op] : $op;

		return array('success' => true, 'msg' => '已' . $verb . '容器 ' . $id);
	}
}
?>
