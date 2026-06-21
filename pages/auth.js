// 身分驗證和權限控制函數
function checkAuth(allowedRoles = []) {
  const userStr = sessionStorage.getItem('currentUser');
  
  if (!userStr) {
    // 未登入，重定向到登入頁面
    window.location.href = '/scholarship/pages/login.html';
    return null;
  }
  
  const user = JSON.parse(userStr);
  
  // 檢查權限
  if (allowedRoles.length > 0 && !allowedRoles.includes(user.type)) {
    alert('您沒有權限訪問此頁面');
    // 根據使用者類型重定向到對應頁面
    switch(user.type) {
      case 'Student':
        window.location.href = '/scholarship/pages/student_dashboard.html';
        break;
      case 'Teacher':
        window.location.href = '/scholarship/pages/teacher.html';
        break;
      case 'SystemAdministrator':
        window.location.href = '/scholarship/pages/admin_scholarships.html';
        break;
      case 'Organization':
        window.location.href = '/scholarship/pages/organization.html';
        break;
      default:
        window.location.href = '/scholarship/pages/login.html';
    }
    return null;
  }
  
  return user;
}

function logout() {
  if (confirm('確定要登出系統嗎？')) {
    sessionStorage.removeItem('currentUser');
    // 使用完整路徑確保正確重定向
    window.location.href = '/scholarship/pages/login.html';
  }
}

// 自動載入通知元件（非登入頁）
(function () {
  if (window.location.pathname.includes('login')) return;
  const s = document.createElement('script');
  s.src = '/scholarship/pages/notifications.js';
  document.head.appendChild(s);
})();
