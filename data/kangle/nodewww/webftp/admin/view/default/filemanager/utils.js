/**
 * filemanager/utils.js —— 管理端文件管理器前端交互（T03）
 *
 * 安全约定（与服务端四层防护配合，不要在前端做任何越权判断）：
 *   - 所有 $.post 经 csrf.js 的 $.ajaxPrefilter 自动追加 _csrf，写操作天然受 CSRF 守卫保护；
 *   - 文件删除走原生 confirm() 二次确认（UI 层），真正的越界/禁区拦截在服务端；
 *   - 下载走 GET（window.location），CSRF 守卫对非写请求只确保 token 存在、放行。
 *
 * 依赖：jQuery（head.html 已引入）、csrf.js（已注入 token）、lucide（图标）。
 */
(function (window, document, $) {
	'use strict';

	/** 当前所在目录（相对 /home/ftp 的路径，以 / 开头） */
	var cur = '/';

	/** 分片大小：2MB，配合服务端分片合并支持大文件上传 */
	var CHUNK = 2 * 1024 * 1024;

	/** 可在线编辑的文件类型（来自 webftp.lib.php 的 getfileicon 分类） */
	var EDITABLE = { txt: 1, unknown: 1 };

	/** 类型 → lucide 图标名 */
	var ICON = {
		folder: 'folder',
		txt: 'file-text',
		zip: 'file-archive',
		image: 'image',
		exe: 'file',
		unknown: 'file'
	};

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function fmtSize(n) {
		n = parseInt(n, 10) || 0;

		if (n < 1024) {
			return n + ' B';
		}

		var u = ['KB', 'MB', 'GB', 'TB'];
		var i = -1;

		do {
			n /= 1024;
			++i;
		} while (n >= 1024 && i < u.length - 1);

		return (i === 0 ? n : n.toFixed(1)) + ' ' + u[i];
	}

	function fmtTime(ts) {
		ts = parseInt(ts, 10) || 0;

		if (ts <= 0) {
			return '-';
		}

		var d = new Date(ts * 1000);
		var p = function (x) { return (x < 10 ? '0' : '') + x; };

		return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate())
			+ ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
	}

	function showMsg(t, ok) {
		var el = ok ? $('#okmsg') : $('#msg');
		var other = ok ? $('#msg') : $('#okmsg');
		other.hide();
		el.removeClass('ep-alert-danger ep-alert-success')
			.addClass(ok ? 'ep-alert-success' : 'ep-alert-danger')
			.text(t).show();

		if (ok) {
			setTimeout(function () { el.fadeOut(400); }, 2500);
		}
	}

	/** 取当前选中的文件名数组 */
	function selectedNames() {
		var arr = [];
		$('#fbody input.fm-check:checked').each(function () {
			arr.push($(this).data('name'));
		});
		return arr;
	}

	function refreshSelInfo() {
		var n = selectedNames().length;
		$('#selInfo').text(n ? ('已选 ' + n + ' 项') : '');
		$('#batchZipBtn').prop('disabled', n === 0);
		$('#batchDelBtn').prop('disabled', n === 0);
	}

	/** 渲染路径面包屑 */
	function renderPath() {
		var trail = $('#pathtrail');
		trail.empty();

		if (cur === '/' || cur === '') {
			return;
		}

		var segs = cur.split('/').filter(function (x) { return x !== ''; });
		var acc = '';

		$.each(segs, function (i, seg) {
			acc += '/' + seg;
			trail.append($('<span> / </span>').css('color', 'var(--color-font-secondary,#94a3b8)'));
			trail.append($('<a href="javascript:;"></a>')
				.text(seg)
				.css('color', 'var(--color-primary,#2563eb)')
				.on('click', function () { load(acc); }));
		});
	}

	/** 列举并渲染当前目录 */
	function load(dir) {
		if (dir !== undefined) {
			cur = dir || '/';
		}

		$.post('?c=filemanager&a=ls', { dir: cur }, function (r) {
			if (!r || !r.success) {
				showMsg((r && r.msg) || '列举失败');
				return;
			}

			cur = r.cwd || '/';
			renderPath();
			render(r);
		}, 'json').fail(function () { showMsg('请求失败'); });
	}

	function render(r) {
		var body = $('#fbody');
		body.empty();

		var list = r.list || [];

		if (!list.length) {
			body.append($('<tr><td colspan="6" class="ep-muted" style="text-align:center;padding:24px;">（空目录）</td></tr>'));
			refreshSelInfo();
			return;
		}

		// 目录在前、文件在后，各自按名称排序
		list.sort(function (a, b) {
			if (a.dir !== b.dir) {
				return a.dir ? -1 : 1;
			}
			return String(a.name).localeCompare(String(b.name), 'zh');
		});

		$.each(list, function (_, it) {
			var name = it.name;
			var isDir = !!it.dir;
			var type = it.type || 'unknown';
			var icon = ICON[type] || 'file';
			var editable = !isDir && EDITABLE[type];

			var tr = $('<tr></tr>');

			// 选择框
			var cb = $('<input type="checkbox" class="fm-check">').attr('data-name', name);
			cb.on('change', refreshSelInfo);
			tr.append($('<td></td>').append(cb));

			// 名称（目录可进入；文件可下载/编辑）
			var nameCell = $('<td></td>');
			var nameLink = $('<a href="javascript:;" style="display:inline-flex;align-items:center;gap:6px;color:inherit;text-decoration:none;"></a>');
			nameLink.append($('<i data-lucide="' + icon + '" style="width:16px;height:16px;color:var(--color-font-secondary,#94a3b8);"></i>'));
			nameLink.append($('<span></span>').text(name));

			if (isDir) {
				nameLink.css('font-weight', '600').on('click', function () {
					load((cur === '/' ? '' : cur) + '/' + name);
				});
			} else {
				nameLink.css('color', 'var(--color-primary,#2563eb)').on('click', function () { download(name); });
			}

			nameCell.append(nameLink);
			tr.append(nameCell);

			// 大小
			tr.append($('<td></td>').text(isDir ? '-' : fmtSize(it.size)));
			// 修改时间
			tr.append($('<td></td>').text(fmtTime(it.mtime)));
			// 权限
			tr.append($('<td></td>').text(it.mode || ''));

			// 操作
			var ops = $('<td></td>').css('white-space', 'nowrap');

			if (!isDir) {
				ops.append(btn('下载', 'btn-sm', function () { download(name); }));

				if (editable) {
					ops.append(btn('编辑', 'btn-sm', function () { editFile(name); }));
				}

				if (type === 'zip') {
					ops.append(btn('解压', 'btn-sm', function () { doExtract(name); }));
				}
			}

			ops.append(btn('重命名', 'btn-sm', function () { doRename(name); }));
			ops.append(btn('复制', 'btn-sm', function () { doCopy(name); }));
			ops.append(btn('移动', 'btn-sm', function () { doMove(name); }));
			ops.append(btn('权限', 'btn-sm', function () { doChmod(name, it.mode); }));
			ops.append(btn('删除', 'btn-sm btn-danger', function () { doDelete(name); }));

			tr.append(ops);
			body.append(tr);
		});

		if (window.lucide) {
			window.lucide.createIcons();
		}

		refreshSelInfo();
	}

	function btn(label, cls, fn) {
		return $('<button class="' + cls + '"></button>').text(label).on('click', fn);
	}

	function doneDefault(r) {
		if (r && r.success) {
			showMsg(r.msg || '操作成功', true);
			load();
		} else {
			showMsg((r && r.msg) || '操作失败');
		}
	}

	function download(name) {
		window.location.href = '?c=filemanager&a=download&dir='
			+ encodeURIComponent(cur) + '&name=' + encodeURIComponent(name);
	}

	function doDelete(name) {
		if (!confirm('确认删除 “' + name + '” ？此操作不可逆。')) {
			return;
		}
		$.post('?c=filemanager&a=delete', { dir: cur, name: name }, doneDefault, 'json').fail(function () { showMsg('请求失败'); });
	}

	function doRename(name) {
		var n = prompt('重命名为：', name);
		if (!n || n === name) {
			return;
		}
		$.post('?c=filemanager&a=rename', { dir: cur, src: name, dst: n }, doneDefault, 'json').fail(function () { showMsg('请求失败'); });
	}

	function doCopy(name) {
		var n = prompt('复制为：', name + '.bak');
		if (!n) {
			return;
		}
		$.post('?c=filemanager&a=copy', { dir: cur, src: name, dst: n }, doneDefault, 'json').fail(function () { showMsg('请求失败'); });
	}

	function doMove(name) {
		var d = prompt('移动到目录（相对于 /home/ftp，留空表示当前目录）：', cur);
		if (d === null) {
			return;
		}
		d = d === '' ? cur : d;
		$.post('?c=filemanager&a=move', { dir: cur, src: name, dst: d }, doneDefault, 'json').fail(function () { showMsg('请求失败'); });
	}

	function doChmod(name, mode) {
		var m = prompt('权限（三位八进制，如 755）：', mode || '755');
		if (!m) {
			return;
		}
		$.post('?c=filemanager&a=chmod', { dir: cur, name: name, mode: m }, doneDefault, 'json').fail(function () { showMsg('请求失败'); });
	}

	function doExtract(name) {
		if (!confirm('确认解压 “' + name + '” 到当前目录？同名文件将被覆盖。')) {
			return;
		}
		$.post('?c=filemanager&a=extract', { dir: cur, name: name }, doneDefault, 'json').fail(function () { showMsg('请求失败'); });
	}

	/* ============================ 文本编辑 ============================ */

	var editing = null; // { name }

	function editFile(name) {
		$.post('?c=filemanager&a=readFile', { dir: cur, name: name }, function (r) {
			if (!r || !r.success) {
				showMsg((r && r.msg) || '读取失败');
				return;
			}
			editing = { name: name };
			$('#editTitle').text('编辑文件：' + name);
			$('#editArea').val(r.content || '');
			$('#editModal').css('display', 'flex');
		}, 'json').fail(function () { showMsg('请求失败'); });
	}

	function closeEdit() {
		$('#editModal').css('display', 'none');
		editing = null;
	}

	function saveEdit() {
		if (!editing) {
			return;
		}
		var name = editing.name;
		var content = $('#editArea').val();
		$.post('?c=filemanager&a=writeFile', { dir: cur, name: name, content: content }, function (r) {
			if (r && r.success) {
				showMsg(r.msg || '已保存', true);
				closeEdit();
				load();
			} else {
				showMsg((r && r.msg) || '保存失败');
			}
		}, 'json').fail(function () { showMsg('请求失败'); });
	}

	/* ============================ 批量操作 ============================ */

	function batchZip() {
		var names = selectedNames();
		if (!names.length) {
			return;
		}
		$.post('?c=filemanager&a=compress', { dir: cur, names: names, zipName: '' }, function (r) {
			if (r && r.success) {
				showMsg('已打包，开始下载', true);
				window.location.href = '?c=filemanager&a=download&dir='
					+ encodeURIComponent(cur) + '&name=' + encodeURIComponent(r.name);
				load();
			} else {
				showMsg((r && r.msg) || '打包失败');
			}
		}, 'json').fail(function () { showMsg('请求失败'); });
	}

	function batchDelete() {
		var names = selectedNames();
		if (!names.length) {
			return;
		}
		if (!confirm('确认删除选中的 ' + names.length + ' 项？此操作不可逆。')) {
			return;
		}
		$.post('?c=filemanager&a=batchDelete', { dir: cur, names: names }, function (r) {
			if (r && r.success) {
				showMsg(r.msg || '已删除', true);
				load();
			} else {
				showMsg((r && r.msg) || '删除失败');
			}
		}, 'json').fail(function () { showMsg('请求失败'); });
	}

	/* ============================ 上传（分片） ============================ */

	function uploadFiles(files) {
		var queue = Array.prototype.slice.call(files);
		var idx = 0;

		next();

		function next() {
			if (idx >= queue.length) {
				$('#prog').text('');
				load();
				return;
			}
			var file = queue[idx++];
			uploadOne(file, 0, next);
		}

		function uploadOne(file, chunk, done) {
			var total = Math.max(1, Math.ceil(file.size / CHUNK));
			var start = chunk * CHUNK;
			var end = Math.min(file.size, start + CHUNK);
			var blob = file.slice(start, end);

			var fd = new FormData();
			fd.append('file', blob, file.name);
			fd.append('dir', cur);
			fd.append('name', file.name);
			fd.append('chunk', chunk);
			fd.append('chunks', total);

			$('#prog').text('上传中 ' + idx + '/' + queue.length + '：' + file.name
				+ ' (' + (chunk + 1) + '/' + total + ')');

			$.ajax({
				url: '?c=filemanager&a=upload',
				type: 'POST',
				data: fd,
				processData: false,
				contentType: false,
				success: function (r) {
					if (!r || !r.success) {
						showMsg((r && r.msg) || '上传失败');
						done();
						return;
					}
					if (chunk + 1 < total) {
						uploadOne(file, chunk + 1, done);
					} else {
						done();
					}
				},
				error: function () {
					showMsg('上传请求失败');
					done();
				}
			});
		}
	}

	/* ============================ 事件绑定 ============================ */

	$(function () {
		$('#refreshBtn').on('click', function () { load(); });
		$('#uploadBtn').on('click', function () { $('#fileInput').click(); });

		$('#fileInput').on('change', function () {
			if (this.files && this.files.length) {
				uploadFiles(this.files);
			}
			this.value = '';
		});

		$('#mkdirBtn').on('click', function () {
			var n = prompt('新建文件夹名称：');
			if (!n) {
				return;
			}
			$.post('?c=filemanager&a=mkdir', { dir: cur, name: n }, doneDefault, 'json').fail(function () { showMsg('请求失败'); });
		});

		$('#batchZipBtn').on('click', batchZip);
		$('#batchDelBtn').on('click', batchDelete);

		$('#checkAll').on('change', function () {
			var checked = this.checked;
			$('#fbody input.fm-check').prop('checked', checked);
			refreshSelInfo();
		});

		$('.fm-root').on('click', function () { load('/'); });

		$('#editCancel').on('click', closeEdit);
		$('#editSave').on('click', saveEdit);
		$('#editModal').on('click', function (e) {
			if (e.target === this) {
				closeEdit();
			}
		});

		// 首次加载
		load('/');
	});

})(window, document, window.jQuery);
