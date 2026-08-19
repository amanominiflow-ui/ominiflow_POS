document.addEventListener('DOMContentLoaded', function () {
    // Profile menu toggle
    const profileTrigger = document.getElementById('profileTrigger');
    const profileMenu = document.getElementById('profileMenu');

    if (profileTrigger && profileMenu) {
        profileTrigger.addEventListener('click', function (e) {
            e.stopPropagation();
            profileMenu.classList.toggle('show');
        });

        document.addEventListener('click', function (e) {
            if (!profileMenu.contains(e.target) && !profileTrigger.contains(e.target)) {
                profileMenu.classList.remove('show');
            }
        });
    }

    // Mobile sidebar toggle
    const sidebarToggle = document.getElementById('sidebarToggle');
    const appSidebar = document.getElementById('appSidebar');

    if (sidebarToggle && appSidebar) {
        sidebarToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            appSidebar.classList.toggle('open');
        });

        document.addEventListener('click', function (e) {
            if (!appSidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
                appSidebar.classList.remove('open');
            }
        });
    }

    // Sidebar Scroll Position Persistence & Active Item Auto-Focus
    const sidebarNav = document.querySelector('.sidebar-nav');
    if (sidebarNav) {
        const savedScroll = sessionStorage.getItem('sidebar_scroll_pos');
        if (savedScroll !== null) {
            sidebarNav.scrollTop = parseInt(savedScroll, 10);
        }

        const activeLink = sidebarNav.querySelector('.nav-item.active');
        if (activeLink) {
            const rect = activeLink.getBoundingClientRect();
            const navRect = sidebarNav.getBoundingClientRect();
            if (rect.top < navRect.top || rect.bottom > navRect.bottom) {
                activeLink.scrollIntoView({ block: 'nearest', behavior: 'instant' });
            }
        }

        sidebarNav.addEventListener('click', function (e) {
            const item = e.target.closest('a.nav-item');
            if (item) {
                sessionStorage.setItem('sidebar_scroll_pos', sidebarNav.scrollTop);
            }
        });
    }
});
