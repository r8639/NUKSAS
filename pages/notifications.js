// 通知中心元件 — 自動注入到所有含 header nav 的頁面
(function () {
  'use strict';
  const API_BASE = '/scholarship/api';

  function getUser() {
    try { return JSON.parse(sessionStorage.getItem('currentUser') || 'null'); }
    catch { return null; }
  }

  function escHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function formatTime(ts) {
    if (!ts) return '';
    const d   = new Date(ts);
    const now = new Date();
    const sec = Math.floor((now - d) / 1000);
    if (sec < 60)   return '剛剛';
    if (sec < 3600) return Math.floor(sec / 60) + ' 分鐘前';
    if (sec < 86400) return Math.floor(sec / 3600) + ' 小時前';
    return d.toLocaleDateString('zh-TW');
  }

  function init() {
    const user = getUser();
    if (!user) return;

    const nav = document.querySelector('header .nav nav');
    if (!nav) return;

    // Build bell HTML
    const wrap = document.createElement('span');
    wrap.id = 'notif-bell-wrap';
    wrap.style.cssText = 'position:relative;display:inline-block;margin-left:18px;vertical-align:middle;';
    wrap.innerHTML = `
      <button id="notif-bell" title="通知"
        style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:19px;padding:0 2px;line-height:1;position:relative;vertical-align:middle;"
        aria-label="通知">
        🔔
        <span id="notif-badge"
          style="display:none;position:absolute;top:-5px;right:-6px;background:#ef4444;color:#fff;
                 border-radius:999px;font-size:10px;font-weight:700;min-width:16px;height:16px;
                 line-height:16px;text-align:center;padding:0 3px;pointer-events:none;"></span>
      </button>
      <div id="notif-dropdown"
        style="display:none;position:absolute;right:0;top:calc(100% + 10px);width:320px;
               background:#111827;border:1px solid #1f2937;border-radius:14px;
               box-shadow:0 24px 60px rgba(0,0,0,0.45);z-index:2000;overflow:hidden;">
        <div style="padding:12px 16px 10px;border-bottom:1px solid #1f2937;
                    display:flex;align-items:center;justify-content:space-between;">
          <span style="font-weight:700;color:#e5e7eb;font-size:14px;">通知</span>
          <button id="notif-read-all"
            style="background:none;border:none;color:#7dd3fc;font-size:12px;cursor:pointer;padding:0;">
            全部標為已讀
          </button>
        </div>
        <div id="notif-list" style="max-height:360px;overflow-y:auto;"></div>
      </div>`;

    // Insert before logout link
    const logoutLink = nav.querySelector('a[onclick*="logout"]');
    if (logoutLink) {
      nav.insertBefore(wrap, logoutLink);
    } else {
      nav.appendChild(wrap);
    }

    document.getElementById('notif-bell').addEventListener('click', toggleDropdown);
    document.getElementById('notif-read-all').addEventListener('click', markAllRead);

    document.addEventListener('click', (e) => {
      const w  = document.getElementById('notif-bell-wrap');
      const dd = document.getElementById('notif-dropdown');
      if (w && dd && !w.contains(e.target) && dd.style.display !== 'none') {
        dd.style.display = 'none';
      }
    });

    loadUnreadCount();
    setInterval(loadUnreadCount, 30000);
  }

  async function loadUnreadCount() {
    const user = getUser();
    if (!user) return;
    try {
      const res  = await fetch(`${API_BASE}/notifications/unread-count?user_id=${encodeURIComponent(user.id)}`);
      if (!res.ok) return;
      const data = await res.json();
      const badge = document.getElementById('notif-badge');
      if (!badge) return;
      const cnt = data.count || 0;
      if (cnt > 0) {
        badge.textContent   = cnt > 99 ? '99+' : cnt;
        badge.style.display = 'inline-block';
      } else {
        badge.style.display = 'none';
      }
    } catch { /* silent */ }
  }

  async function toggleDropdown(e) {
    e.stopPropagation();
    const dd = document.getElementById('notif-dropdown');
    if (!dd) return;
    if (dd.style.display === 'none') {
      dd.style.display = 'block';
      await loadNotifications();
    } else {
      dd.style.display = 'none';
    }
  }

  async function loadNotifications() {
    const user = getUser();
    if (!user) return;
    const list = document.getElementById('notif-list');
    if (!list) return;
    list.innerHTML = '<div style="padding:20px;text-align:center;color:#6b7280;font-size:13px;">載入中…</div>';
    try {
      const res  = await fetch(`${API_BASE}/notifications?user_id=${encodeURIComponent(user.id)}`);
      if (!res.ok) throw new Error();
      const data  = await res.json();
      const items = data.data || [];
      if (items.length === 0) {
        list.innerHTML = '<div style="padding:24px;text-align:center;color:#6b7280;font-size:13px;">目前沒有通知</div>';
        return;
      }
      list.innerHTML = items.map(n => {
        const bg = n.is_read ? 'transparent' : 'rgba(125,211,252,0.06)';
        const dot = !n.is_read
          ? '<span style="width:7px;height:7px;border-radius:50%;background:#22d3ee;margin-top:5px;flex-shrink:0;display:inline-block;"></span>'
          : '<span style="width:7px;flex-shrink:0;display:inline-block;"></span>';
        return `<div class="notif-item" data-id="${n.id}" data-url="${escHtml(n.target_url)}"
          style="padding:11px 16px;border-bottom:1px solid #1f2937;cursor:pointer;background:${bg};transition:background 0.15s;">
          <div style="display:flex;align-items:flex-start;gap:8px;">
            ${dot}
            <div style="flex:1;min-width:0;">
              <div style="font-weight:${n.is_read ? '400' : '600'};color:#e5e7eb;font-size:13px;line-height:1.45;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(n.title)}</div>
              <div style="color:#9ca3af;font-size:12px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(n.content)}</div>
              <div style="color:#4b5563;font-size:11px;margin-top:3px;">${formatTime(n.created_at)}</div>
            </div>
          </div>
        </div>`;
      }).join('');

      list.querySelectorAll('.notif-item').forEach(el => {
        el.addEventListener('click', () => openNotif(el.dataset.id, el.dataset.url));
        el.addEventListener('mouseover', () => { el.style.background = 'rgba(125,211,252,0.1)'; });
        el.addEventListener('mouseout',  () => { el.style.background = el.dataset.read === '1' ? 'transparent' : 'rgba(125,211,252,0.06)'; });
      });
    } catch {
      list.innerHTML = '<div style="padding:20px;text-align:center;color:#ef4444;font-size:13px;">載入失敗</div>';
    }
  }

  async function openNotif(id, url) {
    try { await fetch(`${API_BASE}/notifications/${id}/read`, { method: 'PUT' }); } catch { /* silent */ }
    await loadUnreadCount();
    if (url) window.location.href = url;
  }

  async function markAllRead(e) {
    e.stopPropagation();
    const user = getUser();
    if (!user) return;
    try {
      await fetch(`${API_BASE}/notifications/read-all?user_id=${encodeURIComponent(user.id)}`, { method: 'PUT' });
    } catch { /* silent */ }
    await loadUnreadCount();
    await loadNotifications();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
