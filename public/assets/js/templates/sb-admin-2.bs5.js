// Bootstrap 5 / Vanilla JS version
(() => {
  "use strict";

  const body = document.body;
  const sidebar = document.querySelector(".sidebar");
  const sidebarToggleButtons = document.querySelectorAll("#sidebarToggle, #sidebarToggleTop");
  const scrollToTopBtn = document.querySelector(".scroll-to-top");

  // Collapse utility for BS5
  const collapseElements = sidebar ? sidebar.querySelectorAll(".collapse") : [];

  // Toggle the side navigation
  sidebarToggleButtons.forEach(btn => {
    btn.addEventListener("click", () => {
      body.classList.toggle("sidebar-toggled");
      sidebar.classList.toggle("toggled");

      if (sidebar.classList.contains("toggled")) {
        collapseElements.forEach(c => {
          const bsCollapse = bootstrap.Collapse.getInstance(c) || new bootstrap.Collapse(c);
          bsCollapse.hide();
        });
      }
    });
  });

  // Close any open menu accordions when window is resized below 768px
  window.addEventListener("resize", () => {
    const width = window.innerWidth;

    if (width < 768) {
      collapseElements.forEach(c => {
        const bsCollapse = bootstrap.Collapse.getInstance(c) || new bootstrap.Collapse(c);
        bsCollapse.hide();
      });
    }

    // Toggle the side navigation when window is resized below 480px
    if (width < 480 && !sidebar.classList.contains("toggled")) {
      body.classList.add("sidebar-toggled");
      sidebar.classList.add("toggled");
      collapseElements.forEach(c => {
        const bsCollapse = bootstrap.Collapse.getInstance(c) || new bootstrap.Collapse(c);
        bsCollapse.hide();
      });
    }
  });

  // Prevent the content wrapper from scrolling when fixed side navigation hovered over
  if (body.classList.contains("fixed-nav") && sidebar) {
    sidebar.addEventListener("wheel", (e) => {
      if (window.innerWidth > 768) {
        const delta = e.deltaY;
        sidebar.scrollTop += delta < 0 ? -30 : 30;
        e.preventDefault();
      }
    });
  }

  // Scroll to top button appear
  document.addEventListener("scroll", () => {
    const scrollDistance = window.scrollY || document.documentElement.scrollTop;
    if (scrollToTopBtn) {
      scrollToTopBtn.style.display = scrollDistance > 100 ? "block" : "none";
    }
  });

  // Smooth scrolling
  document.addEventListener("click", (e) => {
    if (e.target.matches("a.scroll-to-top")) {
      e.preventDefault();
      const anchor = document.querySelector(e.target.getAttribute("href"));
      if (anchor) {
        anchor.scrollIntoView({ behavior: "smooth", block: "start" });
      }
    }
  });
})();
