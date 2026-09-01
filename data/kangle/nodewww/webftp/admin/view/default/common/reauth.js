/**
 * reauth.js —— 宿主级操作的二次密码确认弹层（1-D / SEC-12）
 *
 * 为什么必须存在它：
 *   runtime.php 的 csrf_enforce_level() 把「改 SSH 端口 / 开关 SSH / 改宿主 root
 *   密码 / 停止重启容器」等动作登记为 CSRF_REAUTH_STRICT，守卫会在缺少 reauth_pass
 *   时直接 403。这道闸只在前端真的弹出密码框时才是「防护」，否则就只是
 *   「把功能锁死」——三者缺一，管理员都会以为面板坏了。本文件就是补上那一环。
 *
 * 为什么不用原生 prompt()：
 *   prompt() 无法遮蔽输入，密码会明文显示在屏幕上，与「二次确认」的目的背道而驰。
 *
 * 为什么不用 artDialog：
 *   项目虽已引入 artDialog，但其版本 API 与皮肤在各页面用法不一，且本弹层需要
 *   精确控制「密文输入 + 回车提交 + ESC 取消 + 提交期间锁定」。自绘一个轻量层
 *   更可控，也不引入对第三方弹窗组件的隐性依赖。
 *
 * 用法：
 *   epReauth({ title: '安全确认', message: '此操作不可逆，请输入面板管理员密码。' },
 *            function (password) { $.post(url, { reauth_pass: password }, ...); });
 *   用户取消或关闭则不回调。
 *
 * 依赖：无（不依赖 jQuery，便于在任何页面加载顺序下可用）。
 */
(function (window, document) {
	'use strict';

	/** 当前是否已打开弹层，防止宿主级操作被重复触发时叠出多个弹层 */
	var opened = false;

	/**
	 * 构建弹层 DOM
	 * @param {Object} opts 配置项
	 * @param {Function} onConfirm 确认回调，接收密码字符串
	 * @param {Function} onClose 关闭回调（确认或取消都会调用一次）
	 * @returns {HTMLElement} 遮罩层元素
	 */
	function build(opts, onConfirm, onClose) {
		var overlay = document.createElement('div');
		overlay.setAttribute('data-ep-reauth', '1');
		overlay.style.cssText = 'position:fixed;left:0;top:0;right:0;bottom:0;'
			+ 'background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;'
			+ 'z-index:100000;';

		var card = document.createElement('div');
		card.style.cssText = 'width:420px;max-width:92vw;background:#fff;border-radius:10px;'
			+ 'box-shadow:0 18px 48px rgba(15,23,42,.24);overflow:hidden;'
			+ 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;';

		var header = document.createElement('div');
		header.style.cssText = 'padding:16px 20px 0;font-size:16px;font-weight:600;color:#0f172a;';
		header.appendChild(document.createTextNode(opts.title || '安全确认'));

		var body = document.createElement('div');
		body.style.cssText = 'padding:10px 20px 16px;font-size:13px;line-height:1.7;color:#475569;';
		body.appendChild(document.createTextNode(opts.message || ''));

		var input = document.createElement('input');
		input.type = 'password';
		input.autocomplete = 'off';
		input.placeholder = '请输入面板管理员密码';
		input.style.cssText = 'width:100%;box-sizing:border-box;margin-top:12px;padding:9px 12px;'
			+ 'border:1px solid #cbd5e1;border-radius:6px;font-size:14px;outline:none;';
		body.appendChild(input);

		var err = document.createElement('div');
		err.style.cssText = 'display:none;margin-top:8px;font-size:12px;color:#dc2626;';
		body.appendChild(err);

		var footer = document.createElement('div');
		footer.style.cssText = 'padding:12px 20px 18px;display:flex;justify-content:flex-end;gap:10px;';

		var cancel = document.createElement('button');
		cancel.type = 'button';
		cancel.appendChild(document.createTextNode('取消'));
		cancel.style.cssText = 'padding:8px 16px;border:1px solid #cbd5e1;background:#fff;'
			+ 'color:#334155;border-radius:6px;font-size:13px;cursor:pointer;';

		var ok = document.createElement('button');
		ok.type = 'button';
		ok.appendChild(document.createTextNode('确认'));
		ok.style.cssText = 'padding:8px 16px;border:none;background:#dc2626;color:#fff;'
			+ 'border-radius:6px;font-size:13px;cursor:pointer;';

		footer.appendChild(cancel);
		footer.appendChild(ok);

		card.appendChild(header);
		card.appendChild(body);
		card.appendChild(footer);
		overlay.appendChild(card);

		function close() {
			if (opened) {
				opened = false;
			}
			document.removeEventListener('keydown', onKey, true);
			if (overlay.parentNode) {
				overlay.parentNode.removeChild(overlay);
			}
			if (typeof onClose === 'function') {
				onClose();
			}
		}

		function submit() {
			var value = input.value;

			if (!value) {
				err.textContent = '请输入密码后再确认。';
				err.style.display = 'block';
				input.focus();
				return;
			}

			ok.disabled = true;
			ok.textContent = '校验中…';
			close();
			onConfirm(value);
		}

		function onKey(event) {
			if (event.key === 'Escape') {
				event.stopPropagation();
				event.preventDefault();
				close();
			} else if (event.key === 'Enter') {
				event.stopPropagation();
				event.preventDefault();
				submit();
			}
		}

		cancel.addEventListener('click', close);
		ok.addEventListener('click', submit);
		// 点遮罩空白处关闭；点卡片内部不关闭，避免误触中断输入
		overlay.addEventListener('click', function (event) {
			if (event.target === overlay) {
				close();
			}
		});
		document.addEventListener('keydown', onKey, true);

		return overlay;
	}

	/**
	 * 弹出二次密码确认
	 *
	 * @param {Object}   opts      { title, message }
	 * @param {Function} callback  用户确认并输入密码后调用，接收密码；取消则不调用
	 * @returns {boolean} 弹层是否成功打开（已有一个打开中则返回 false）
	 */
	window.epReauth = function (opts, callback) {
		if (opened || typeof callback !== 'function') {
			return false;
		}

		opened = true;

		var overlay = build(opts || {}, callback, function () {
			opened = false;
		});

		(document.body || document.documentElement).appendChild(overlay);

		var input = overlay.getElementsByTagName('input')[0];

		if (input) {
			input.focus();
		}

		return true;
	};

	/**
	 * 便捷封装：先弹二次确认，再把密码并入既有请求参数后发起 $.post
	 *
	 * 为什么单独提供：宿主级操作的处理函数高度相似（都是「确认 → 带 reauth_pass
	 * 重发一次」），集中封装可避免每个页面各自拼参数时漏掉 reauth_pass。
	 *
	 * @param {Object}   opts     { title, message }
	 * @param {string}   url      请求地址
	 * @param {Object}   data     业务参数（本函数会追加 reauth_pass，调用方无需关心）
	 * @param {Function} done     $.post 的成功回调
	 * @param {Function} fail     $.post 的失败回调（可选）
	 * @returns {void}
	 */
	window.epReauthPost = function (opts, url, data, done, fail) {
		window.epReauth(opts, function (password) {
			var payload = data || {};
			payload.reauth_pass = password;

			var jq = window.jQuery ? window.jQuery.post(url, payload, done, 'json') : null;

			if (jq && typeof jq.fail === 'function') {
				jq.fail(function (xhr) {
					// 401：会话过期。若不在此统一兜住，各调用点只会看到一个
					// 解析失败的请求，用户得到的提示是「未知错误」，
					// 而真实原因（需要重新登录）被吞掉。
					if (xhr && xhr.status === 401) {
						var m = '会话已过期，请重新登录后再试。';

						try {
							var j = JSON.parse(xhr.responseText);
							if (j && j.msg) {
								m = j.msg;
							}
						} catch (e) {}

						alert(m);
						window.top.location.href = '?c=session&a=loginForm';

						return;
					}

					if (typeof fail === 'function') {
						fail.apply(this, arguments);
					} else if (xhr && xhr.status === 403) {
						alert('二次确认失败或无权执行该操作。');
					}
				});
			}
		});
	};
}(window, document));
