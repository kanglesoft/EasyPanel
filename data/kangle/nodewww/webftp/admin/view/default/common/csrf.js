/**
 * csrf.js —— 管理端 CSRF token 全局注入（AD-4 / D3）
 *
 * 为什么需要它：面板有 31 个控制器、上百处 $.post 与原生表单，逐个加隐藏域
 * 既不现实也必然遗漏。本文件用两个全局钩子一次性覆盖：
 *   1. $.ajaxPrefilter  —— 给所有 jQuery 发起的写请求追加 _csrf 参数；
 *   2. submit 捕获监听   —— 给所有原生 <form method="post"> 注入隐藏域。
 *
 * token 来源是 <meta name="csrf-token">，由 Control::fetch() 统一 assign、
 * common/head.html 输出，因此任何使用了 head.html 的页面都自动生效。
 *
 * 依赖：jQuery（页面已在 head.html 中引入 /style/jquery.js）。
 */
(function (window, document) {
	'use strict';

	var META_NAME = 'csrf-token';
	var FIELD_NAME = '_csrf';
	var HEADER_NAME = 'X-CSRF-Token';

	/**
	 * 读取当前页面持有的 CSRF token
	 * @returns {string} token；取不到返回空字符串
	 */
	function epCsrfToken() {
		// 优先取显式全局变量（便于后续任务在特殊页面里覆盖）
		if (window.EP_CSRF_TOKEN) {
			return String(window.EP_CSRF_TOKEN);
		}

		var meta = document.querySelector('meta[name="' + META_NAME + '"]');

		if (meta && meta.getAttribute('content')) {
			return meta.getAttribute('content');
		}

		return '';
	}

	/**
	 * 判断是否为需要携带 token 的写请求
	 * @param {string} method 大写 HTTP 方法
	 * @returns {boolean}
	 */
	function isWriteMethod(method) {
		return method === 'POST' || method === 'PUT' || method === 'DELETE' || method === 'PATCH';
	}

	/**
	 * 判断当前请求的 body 是否为 JSON（jQuery 侧按 contentType 判定）
	 * JSON body 不能被拼成查询串，否则会破坏载荷结构
	 * @param {Object} options jQuery ajax options
	 * @returns {boolean}
	 */
	function isJsonBody(options) {
		var contentType = options.contentType || (options.headers && options.headers['Content-Type']) || '';
		return String(contentType).indexOf('application/json') !== -1;
	}

	/**
	 * 给单个原生表单注入 _csrf 隐藏域（幂等：已有同名域则跳过）
	 * @param {HTMLFormElement} form
	 * @param {string} token
	 * @returns {void}
	 */
	function injectIntoForm(form, token) {
		if (!form || !token) {
			return;
		}

		var method = (form.getAttribute('method') || 'GET').toUpperCase();

		if (!isWriteMethod(method)) {
			return;
		}

		// elements 支持按 name 索引，命中即表示已注入或页面自己写了隐藏域
		if (form.elements && form.elements[FIELD_NAME]) {
			return;
		}

		var input = document.createElement('input');
		input.type = 'hidden';
		input.name = FIELD_NAME;
		input.value = token;
		form.appendChild(input);
	}

	/**
	 * 页面内所有原生 POST 表单注入一次（覆盖静态表单，登录页即属此类）
	 * @returns {void}
	 */
	function injectIntoAllForms() {
		var token = epCsrfToken();

		if (!token) {
			return;
		}

		var forms = document.getElementsByTagName('form');
		var i;

		for (i = 0; i < forms.length; i++) {
			injectIntoForm(forms[i], token);
		}
	}

	/**
	 * jQuery 全局前置过滤：给所有写请求追加 token
	 *
	 * 覆盖 $.post / $.get 之外的全部 jQuery 请求。之所以也包含 PUT/DELETE/PATCH，
	 * 是因为这些同样是写操作；本项目当前只用到 POST，但守卫侧同样会拦它们。
	 */
	if (window.jQuery) {
		window.jQuery.ajaxPrefilter(function (options, originalOptions, jqXHR) {
			var method = String(options.type || options.method || 'GET').toUpperCase();

			if (!isWriteMethod(method)) {
				return;
			}

			var token = epCsrfToken();

			if (!token) {
				return;
			}

			// JSON 载荷不能拼查询串，改为走请求头（服务端 csrf_request_token() 支持）
			if (isJsonBody(options)) {
				options.headers = options.headers || {};
				options.headers[HEADER_NAME] = token;
				return;
			}

			if (options.data === undefined || options.data === null) {
				options.data = FIELD_NAME + '=' + encodeURIComponent(token);
				return;
			}

			if (typeof options.data === 'string') {
				if (options.data.indexOf(FIELD_NAME + '=') === -1) {
					options.data += (options.data ? '&' : '') + FIELD_NAME + '=' + encodeURIComponent(token);
				}
				return;
			}

			// FormData（文件上传场景）：用 append，避免覆盖已填内容
			if (typeof FormData !== 'undefined' && options.data instanceof FormData) {
				if (typeof options.data.has === 'function' && !options.data.has(FIELD_NAME)) {
					options.data.append(FIELD_NAME, token);
				}
				return;
			}

			if (typeof options.data === 'object') {
				if (options.data[FIELD_NAME] === undefined) {
					options.data[FIELD_NAME] = token;
				}
			}
		});
	}

	/**
	 * 安全网：在捕获阶段拦截 submit。
	 *
	 * 为什么用捕获阶段：业务代码可能在冒泡阶段注册 submit 处理器并立即
	 * serialize 表单；捕获阶段先于任何业务处理器执行，能保证隐藏域一定
	 * 在序列化之前就位，动态生成的表单同样受益。
	 */
	if (document.addEventListener) {
		document.addEventListener('submit', function (event) {
			injectIntoForm(event.target, epCsrfToken());
		}, true);
	}

	// 静态表单在 DOM 就绪后先注入一次，不依赖提交时机
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', injectIntoAllForms);
	} else {
		injectIntoAllForms();
	}

	// 对外暴露，供后续任务（文件管理分片上传等）直接取值
	window.epCsrfToken = epCsrfToken;
	window.EP_CSRF_FIELD_NAME = FIELD_NAME;
	window.EP_CSRF_HEADER_NAME = HEADER_NAME;
}(window, document));
