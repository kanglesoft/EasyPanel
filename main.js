/* ============================================================
   EasyPanel Heavy 官网交互逻辑
   - 复制按钮（带成功反馈）
   - 安装方式 Tab 切换
   - 移动端汉堡菜单
   - 点击导航后自动收起移动端菜单
   纯原生实现，无外部依赖。
   ============================================================ */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    initCopyButtons();
    initInstallTabs();
    initMobileNav();
  });

  /* ---------- 复制按钮 ---------- */
  function initCopyButtons() {
    var buttons = document.querySelectorAll('.copy-btn');
    buttons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var targetId = btn.getAttribute('data-copy');
        var source = document.getElementById(targetId);
        if (!source) return;
        var text = source.textContent;
        copyText(text).then(function (ok) {
          if (ok) {
            showCopied(btn);
          } else {
            // 降级：选中文本提示用户手动复制
            fallbackCopy(source);
          }
        });
      });
    });
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).then(function () {
        return true;
      }).catch(function () {
        return false;
      });
    }
    return Promise.resolve(false);
  }

  // 老浏览器或非安全上下文下的降级方案
  function fallbackCopy(source) {
    var range = document.createRange();
    range.selectNodeContents(source);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    try {
      document.execCommand('copy');
      var btn = source.closest('.code-block').querySelector('.copy-btn');
      if (btn) showCopied(btn);
    } catch (e) {
      /* 忽略，用户可手动复制 */
    }
    sel.removeAllRanges();
  }

  function showCopied(btn) {
    var original = '复制';
    btn.textContent = '已复制!';
    btn.classList.add('copied');
    window.clearTimeout(btn._timer);
    btn._timer = window.setTimeout(function () {
      btn.textContent = original;
      btn.classList.remove('copied');
    }, 1800);
  }

  /* ---------- 安装方式 Tab ---------- */
  function initInstallTabs() {
    var tabBtns = document.querySelectorAll('.tab-btn');
    var panels = document.querySelectorAll('.tab-panel');

    tabBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var target = btn.getAttribute('data-tab');

        tabBtns.forEach(function (b) {
          var active = b === btn;
          b.classList.toggle('active', active);
          b.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        panels.forEach(function (panel) {
          panel.classList.toggle('active', panel.getAttribute('data-panel') === target);
        });
      });
    });
  }

  /* ---------- 移动端导航 ---------- */
  function initMobileNav() {
    var toggle = document.getElementById('navToggle');
    var links = document.getElementById('navLinks');
    if (!toggle || !links) return;

    toggle.addEventListener('click', function () {
      var open = links.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    // 点击任意导航链接后收起菜单（移动端）
    links.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () {
        if (window.innerWidth <= 720) {
          links.classList.remove('open');
          toggle.setAttribute('aria-expanded', 'false');
        }
      });
    });
  }
})();
