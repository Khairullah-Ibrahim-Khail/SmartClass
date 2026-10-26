/**
 * Smart Class Dashboard - Complete JavaScript
 * Includes main dashboard functionality and profile dropdown
 * Optimized for performance, accessibility, and fixed z-index issues
 */

(function ($) {
  "use strict";

  // ============================================
  // MAIN DASHBOARD FUNCTIONALITY
  // ============================================

  const Dashboard = {
    // Initialize state
    state: {
      screenMode: "large",
      sidebarCollapsed: false,
      // ADD THIS:
      sidebarExpanded: false, // For medium screens (721px-1024px)
      activeDropdown: null,
      isMobileSidebarOpen: false,
      isExternalDropdownOpen: false,
    },
    // DOM Elements cache
    elements: {},

    // Constants
    BREAKPOINTS: {
      LARGE: 1024,
      MEDIUM: 721,
      SMALL: 720,
    },

    /**
     * Initialize the dashboard
     */
    init: function () {
      this.cacheElements();
      this.loadState();
      // SmartClass: the section of the current page may already be open (app_header.php) — track it
      const $openOnLoad = this.elements.$sidebar.find(".menu-item.active");
      if ($openOnLoad.length) this.state.activeDropdown = $openOnLoad[0];
      this.checkScreenMode();
      this.setupEventListeners();
      this.setupTooltips();
      this.updateLayout();

      // Initialize profile dropdown
      ProfileDropdown.init();

      // Add loaded class to body for CSS transitions
      $("body").addClass("loaded");
    },

    /**
     * Cache frequently used DOM elements
     */
    cacheElements: function () {
      this.elements = {
        $body: $("body"),
        $sidebar: $(".sidebar"),
        $mainWrapper: $(".main-wrapper"),
        $overlay: $(".overlay"),
        $tooltip: $(".tooltip"),
        $externalWrapper: $(".external-dropdown-wrapper"),
        $sidebarToggle: $(".sidebar-toggle"),
        $mobileToggle: $(".mobile-toggle"),
        $menuItems: $(".menu-item"),
        $menuHeaders: $(".menu-header"),
      };
    },

    /**
     * Load state from localStorage
     */
    loadState: function () {
      const savedState = localStorage.getItem("dashboardState");
      if (savedState) {
        try {
          const parsedState = JSON.parse(savedState);
          this.state.sidebarCollapsed = parsedState.sidebarCollapsed || false;

          // Apply loaded state
          if (this.state.sidebarCollapsed) {
            this.elements.$sidebar.addClass("collapsed");
          }
        } catch (e) {
          console.warn("Failed to load saved state:", e);
        }
      }
    },

    /**
     * Save state to localStorage
     */
    saveState: function () {
      try {
        localStorage.setItem(
          "dashboardState",
          JSON.stringify({
            sidebarCollapsed: this.state.sidebarCollapsed,
          })
        );
      } catch (e) {
        console.warn("Failed to save state:", e);
      }
    },

    /**
     * Check and update screen mode
     */
    checkScreenMode: function () {
      const width = $(window).width();
      const previousMode = this.state.screenMode;

      if (width > this.BREAKPOINTS.LARGE) {
        this.state.screenMode = "large";
      } else if (width >= this.BREAKPOINTS.MEDIUM) {
        this.state.screenMode = "medium";
      } else {
        this.state.screenMode = "small";
      }

      if (previousMode !== this.state.screenMode) {
        this.handleScreenModeChange();
      }
    },

    /**
     * Handle screen mode changes - FIXED: Clean up leftover classes
     */
    handleScreenModeChange: function () {
      const { $sidebar, $mainWrapper, $overlay } = this.elements;

      // FIX: Clean up all leftover classes before switching modes
      $sidebar.removeClass("active expanded");
      $overlay.removeClass("active");
      $mainWrapper.css({
        "margin-left": "",
        transform: "",
      });

      // Reset state variables
      this.state.isMobileSidebarOpen = false;
      this.closeAllDropdowns();
      ProfileDropdown.close();

      // FIX: Reset medium screen sidebar state
      if (this.state.screenMode === "medium") {
        this.state.sidebarExpanded = false; // Default to collapsed on medium
      } else if (this.state.screenMode === "large") {
        // FIX: Ensure proper cleanup for large screens
        $sidebar.removeClass("expanded");
      }

      this.updateLayout();
      this.updateAriaAttributes();
    },

    /**
     * Update layout based on current state - FIXED: Better cleanup
     */
    updateLayout: function () {
      const { $sidebar, $mainWrapper, $overlay, $mobileToggle } = this.elements;

      // FIX: Clean up overlay and mobile states first
      $overlay.removeClass("active");
      $mobileToggle.attr("aria-expanded", "false");

      switch (this.state.screenMode) {
        case "large":
          // FIX: Hide mobile toggle on large screens
          $mobileToggle.hide();

          // FIX: Remove medium screen classes
          $sidebar.removeClass("expanded");

          if (this.state.sidebarCollapsed) {
            $sidebar.addClass("collapsed");
            this.updateSidebarToggleIcon("rotate");
          } else {
            $sidebar.removeClass("collapsed");
            this.updateSidebarToggleIcon("normal");
          }
          break;

        case "medium":
          // FIX: Hide mobile toggle on medium screens
          $mobileToggle.hide();

          // FIX: Toggle between collapsed and expanded states
          if (this.state.sidebarExpanded) {
            $sidebar.addClass("expanded");
            $sidebar.removeClass("collapsed");
            this.updateSidebarToggleIcon("normal");
          } else {
            $sidebar.addClass("collapsed");
            $sidebar.removeClass("expanded");
            this.updateSidebarToggleIcon("rotate");
          }
          break;

        case "small":
          // FIX: Show mobile toggle on small screens
          $mobileToggle.show();

          $sidebar.removeClass("collapsed expanded");
          $mobileToggle.attr("aria-expanded", this.state.isMobileSidebarOpen);

          if (this.state.isMobileSidebarOpen) {
            $sidebar.addClass("active");
            $overlay.addClass("active");
            this.updateSidebarToggleIcon("normal");
          } else {
            $sidebar.removeClass("active");
            this.updateSidebarToggleIcon("normal");
          }
          break;
      }

      this.setupTooltips();
    },

    /**
     * Update sidebar toggle button icon
     */
    updateSidebarToggleIcon: function (state) {
      const $icon = this.elements.$sidebarToggle.find("i");

      switch (state) {
        case "rotate":
          $icon.css("transform", "rotate(180deg)");
          break;
        case "normal":
          $icon.css("transform", "rotate(0deg)");
          break;
      }
    },

    /**
     * Update ARIA attributes for accessibility - FIXED: Handle medium screen
     */
    updateAriaAttributes: function () {
      const { $sidebar, $mobileToggle } = this.elements;

      // Update sidebar aria-expanded
      if (this.state.screenMode === "small") {
        $sidebar.attr("aria-expanded", this.state.isMobileSidebarOpen);
        $mobileToggle.attr("aria-expanded", this.state.isMobileSidebarOpen);
      } else if (this.state.screenMode === "large") {
        $sidebar.attr("aria-expanded", !this.state.sidebarCollapsed);
      } else if (this.state.screenMode === "medium") {
        $sidebar.attr("aria-expanded", this.state.sidebarExpanded);
      }
    },

    /**
     * Setup event listeners with event delegation
     */
    setupEventListeners: function () {
      const self = this;
      const { $body, $overlay, $externalWrapper } = this.elements;

      // Window resize with debounce
      let resizeTimer;
      $(window).on("resize", function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => self.checkScreenMode(), 150);
      });

      // Sidebar toggle
      $(".sidebar-toggle").on("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        self.toggleSidebar();
      });

      // Mobile toggle
      $(".mobile-toggle").on("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        self.toggleMobileSidebar();
      });

      // Overlay click
      $overlay.on("click", function () {
        if (self.state.isMobileSidebarOpen) {
          self.closeMobileSidebar();
        }
      });

      // Menu clicks with event delegation
      $body.on("click", ".menu-header", function (e) {
        // Direct links (Dashboard, Classes, Lectures, ...) navigate; only dropdown buttons toggle
        const href = this.tagName === "A" ? this.getAttribute("href") : null;
        if (href && href !== "#") return;
        e.preventDefault();
        e.stopPropagation();
        self.handleMenuClick($(this));
      });

      // Close dropdowns when clicking outside (except profile dropdown)
      $body.on("click", function (e) {
        const $target = $(e.target);

        // Don't close if clicking on profile dropdown or its trigger
        if ($target.closest(".profile-container").length) {
          return;
        }

        // Close other dropdowns
        if (!$target.closest(".menu-item, .external-dropdown").length) {
          self.closeAllDropdowns();
        }

        // Close mobile sidebar when clicking outside on small screens
        if (
          self.state.screenMode === "small" &&
          self.state.isMobileSidebarOpen &&
          !$target.closest(".sidebar, .mobile-toggle").length
        ) {
          self.closeMobileSidebar();
        }
      });

      // Keyboard navigation
      $body.on("keydown", function (e) {
        // Escape key closes everything
        if (e.key === "Escape") {
          if (self.state.isMobileSidebarOpen) {
            self.closeMobileSidebar();
          }
          self.closeAllDropdowns();
          ProfileDropdown.close();
        }

        // Tab key management for accessibility
        if (e.key === "Tab" && self.state.isMobileSidebarOpen) {
          self.handleTabInMobileSidebar(e);
        }
      });

      // Prevent clicks inside external dropdown from closing it
      $externalWrapper.on("click", ".external-dropdown", function (e) {
        e.stopPropagation();
      });
    },

    /**
     * Toggle sidebar (large/medium screens) - FIXED: Better state management
     */
    toggleSidebar: function () {
      if (this.state.screenMode === "small") return;

      if (this.state.screenMode === "large") {
        // Large screen behavior
        this.state.sidebarCollapsed = !this.state.sidebarCollapsed;
        this.elements.$sidebar.toggleClass("collapsed");
        this.closeAllDropdowns();
        this.saveState();

        // Update toggle button icon
        this.updateSidebarToggleIcon(
          this.state.sidebarCollapsed ? "rotate" : "normal"
        );
      } else if (this.state.screenMode === "medium") {
        // FIX: Medium screen behavior - toggle between collapsed/expanded
        this.state.sidebarExpanded = !this.state.sidebarExpanded;

        if (this.state.sidebarExpanded) {
          this.elements.$sidebar.removeClass("collapsed").addClass("expanded");
          this.updateSidebarToggleIcon("normal");
        } else {
          this.elements.$sidebar.removeClass("expanded").addClass("collapsed");
          this.updateSidebarToggleIcon("rotate");
        }

        this.closeAllDropdowns();
      }

      // Update ARIA
      this.updateAriaAttributes();

      // FIX: Update tooltips immediately
      this.setupTooltips();
    },
    /**
     * Toggle mobile sidebar
     */
    toggleMobileSidebar: function () {
      if (this.state.screenMode !== "small") return;

      if (this.state.isMobileSidebarOpen) {
        this.closeMobileSidebar();
      } else {
        this.openMobileSidebar();
      }
    },

    /**
     * Open mobile sidebar
     */
    openMobileSidebar: function () {
      const { $sidebar, $overlay, $mainWrapper, $body } = this.elements;

      $sidebar.addClass("active");
      $overlay.addClass("active");
      $mainWrapper.css(
        "transform",
        `translateX(${document.documentElement.dir === "rtl" ? "-" : ""}${
          (getComputedStyle(document.documentElement).getPropertyValue(
            "--sidebar-width-lg"
          ) || "210px").trim()
        })`
      );

      this.state.isMobileSidebarOpen = true;
      $body.css("overflow", "hidden");

      // Close profile dropdown when opening sidebar
      ProfileDropdown.close();

      // Update ARIA
      this.updateAriaAttributes();

      // Focus management for accessibility
      setTimeout(() => {
        $sidebar.find(".menu-header").first().focus();
      }, 100);
    },

    /**
     * Close mobile sidebar
     */
    closeMobileSidebar: function () {
      const { $sidebar, $overlay, $mainWrapper, $body, $mobileToggle } =
        this.elements;

      $sidebar.removeClass("active");
      $overlay.removeClass("active");
      $mainWrapper.css("transform", "translateX(0)");

      this.state.isMobileSidebarOpen = false;
      $body.css("overflow", "");

      // Update ARIA
      this.updateAriaAttributes();

      // Return focus to mobile toggle
      $mobileToggle.focus();
    },

    /**
     * Handle menu item clicks
     */
    handleMenuClick: function ($menuHeader) {
      const $menuItem = $menuHeader.closest(".menu-item");
      const $dropdown = $menuItem.find(".dropdown-content");

      // Update ARIA
      const isExpanded = $menuHeader.attr("aria-expanded") === "true";
      $menuHeader.attr("aria-expanded", !isExpanded);
      $dropdown.attr("aria-hidden", isExpanded);

      // Close other dropdowns
      if (
        this.state.activeDropdown &&
        this.state.activeDropdown !== $menuItem[0]
      ) {
        this.closeAllDropdowns();
      }

      // Check if we need external dropdown
      const needsExternalDropdown =
        (this.state.screenMode === "large" && this.state.sidebarCollapsed) ||
        (this.state.screenMode === "medium" && !this.state.sidebarExpanded);

      if (needsExternalDropdown) {
        this.handleExternalDropdown($menuItem, $dropdown);
      } else {
        this.handleInternalDropdown($menuItem, $dropdown);
      }
    },

    /**
     * Handle internal dropdown (expanded sidebar)
     */
    handleInternalDropdown: function ($menuItem, $dropdown) {
      if ($menuItem.hasClass("active")) {
        $menuItem.removeClass("active");
        $dropdown.css("max-height", "0");
        this.state.activeDropdown = null;
      } else {
        $menuItem.addClass("active");
        $dropdown.css("max-height", $dropdown[0].scrollHeight + "px");
        this.state.activeDropdown = $menuItem[0];
      }
    },

    /**
     * Handle external dropdown (collapsed sidebar)
     */
    handleExternalDropdown: function ($menuItem, $dropdown) {
      const { $externalWrapper } = this.elements;

      // Close if already open
      if (
        this.state.isExternalDropdownOpen &&
        this.state.activeDropdown === $menuItem[0]
      ) {
        this.closeExternalDropdown();
        return;
      }

      // Close any existing external dropdown
      if (this.state.isExternalDropdownOpen) {
        this.closeExternalDropdown();
      }

      // Create external dropdown
      $externalWrapper.empty();
      const $externalDropdown = $(
        '<div class="external-dropdown" role="menu"></div>'
      );

      // Clone dropdown items
      $dropdown.find(".dropdown-item").each(function () {
        const $original = $(this);
        const $clone = $original.clone(true);

        // Add click handler to close dropdown
        $clone.on("click", function (e) {
          e.stopPropagation();
          setTimeout(() => Dashboard.closeExternalDropdown(), 100);
        });

        $externalDropdown.append($clone);
      });

      $externalWrapper.html($externalDropdown);

      // Position the dropdown
      this.positionExternalDropdown($menuItem, $externalDropdown);

      // Animate in
      setTimeout(() => {
        $externalDropdown.addClass("active");
      }, 10);

      $menuItem.addClass("active");
      this.state.activeDropdown = $menuItem[0];
      this.state.isExternalDropdownOpen = true;

      // Update position on scroll
      this.setupExternalDropdownScroll($menuItem, $externalDropdown);
    },

    /**
     * Position external dropdown
     */
    positionExternalDropdown: function ($menuItem, $externalDropdown) {
      const menuItemRect = $menuItem[0].getBoundingClientRect();
      const sidebarRect = this.elements.$sidebar[0].getBoundingClientRect();

      // Position next to sidebar
      const top =
        menuItemRect.top - sidebarRect.top + this.elements.$sidebar.scrollTop();
      const left = this.elements.$sidebar.width();

      $externalDropdown.css({
        top: top + "px",
        left: left + "px",
      });
    },

    /**
     * Setup scroll tracking for external dropdown
     */
    setupExternalDropdownScroll: function ($menuItem, $externalDropdown) {
      const self = this;
      const initialTop = $externalDropdown.position().top;
      const $sidebar = this.elements.$sidebar;

      const scrollHandler = function () {
        if (
          self.state.isExternalDropdownOpen &&
          self.state.activeDropdown === $menuItem[0]
        ) {
          $externalDropdown.css(
            "top",
            initialTop + $sidebar.scrollTop() + "px"
          );
        }
      };

      $sidebar
        .off("scroll.externalDropdown")
        .on("scroll.externalDropdown", scrollHandler);
    },

    /**
     * Close external dropdown
     */
    closeExternalDropdown: function () {
      const { $externalWrapper, $sidebar } = this.elements;

      $sidebar.off("scroll.externalDropdown");
      $externalWrapper.empty();

      if (this.state.activeDropdown) {
        $(this.state.activeDropdown).removeClass("active");
        $(this.state.activeDropdown)
          .find(".menu-header")
          .attr("aria-expanded", "false");
        $(this.state.activeDropdown)
          .find(".dropdown-content")
          .attr("aria-hidden", "true")
          .css("max-height", "0");
      }

      this.state.activeDropdown = null;
      this.state.isExternalDropdownOpen = false;
    },

    /**
     * Close all dropdowns (except profile dropdown)
     */
    closeAllDropdowns: function () {
      const { $menuItems, $sidebar } = this.elements;

      $sidebar.off("scroll.externalDropdown");
      $menuItems.removeClass("active");
      $menuItems.find(".menu-header").attr("aria-expanded", "false");
      $menuItems
        .find(".dropdown-content")
        .attr("aria-hidden", "true")
        .css("max-height", "0");

      if (this.state.isExternalDropdownOpen) {
        this.closeExternalDropdown();
      }

      this.state.activeDropdown = null;
    },

    /**
     * Setup tooltips - FIXED: Better cleanup
     */
    setupTooltips: function () {
      const self = this;
      const { $tooltip } = this.elements;

      // FIX: Remove all existing handlers first
      $("[data-tooltip]").off("mouseenter.tooltip mouseleave.tooltip");

      // Only show tooltips on medium and small screens when sidebar is collapsed
      if (
        this.state.screenMode === "medium" ||
        (this.state.screenMode === "large" && this.state.sidebarCollapsed)
      ) {
        $("[data-tooltip]").each(function () {
          const $element = $(this);
          const tooltipText = $element.data("tooltip");

          if (!tooltipText) return;

          // FIX: Don't show tooltips for header-nav on large screens
          if (
            self.state.screenMode === "large" &&
            $element.closest(".header-nav").length
          ) {
            return;
          }

          $element
            .on("mouseenter.tooltip", function (e) {
              const rect = this.getBoundingClientRect();

              $tooltip.text(tooltipText).css({
                left: rect.left + rect.width / 2 - 35 + "px",
                top: rect.top + 50 + "px",
                opacity: "1",
              });
            })
            .on("mouseleave.tooltip", function () {
              $tooltip.css("opacity", "0");
            });
        });
      } else {
        $tooltip.css("opacity", "0");
      }
    },

    /**
     * Handle tab key in mobile sidebar for accessibility
     */
    handleTabInMobileSidebar: function (e) {
      const { $sidebar } = this.elements;
      const $focusable = $sidebar.find(
        'button, a, input, select, textarea, [tabindex]:not([tabindex="-1"])'
      );
      const $firstFocusable = $focusable.first();
      const $lastFocusable = $focusable.last();

      if (e.shiftKey && document.activeElement === $firstFocusable[0]) {
        e.preventDefault();
        $lastFocusable.focus();
      } else if (!e.shiftKey && document.activeElement === $lastFocusable[0]) {
        e.preventDefault();
        $firstFocusable.focus();
      }
    },

    /**
     * Clean up (for single-page applications)
     */
    destroy: function () {
      const { $body } = this.elements;

      $body.off("click keydown");
      $(window).off("resize");
      $("[data-tooltip]").off("mouseenter.tooltip mouseleave.tooltip");

      this.closeAllDropdowns();
      this.closeMobileSidebar();
      ProfileDropdown.destroy();
    },
  };

  // ============================================
  // PROFILE DROPDOWN FUNCTIONALITY
  // ============================================

  const ProfileDropdown = {
    // State
    isOpen: false,

    // Elements
    elements: {},

    /**
     * Initialize profile dropdown
     */
    init: function () {
      this.cacheElements();

      // Check if profile dropdown exists
      if (!this.elements.trigger || !this.elements.dropdown) {
        console.warn("Profile dropdown elements not found");
        return;
      }

      this.setupEventListeners();
      this.setupAccessibility();
    },

    /**
     * Cache DOM elements
     */
    cacheElements: function () {
      this.elements = {
        trigger: document.getElementById("profile-dropdown-trigger"),
        dropdown: document.getElementById("profile-dropdown"),
        container: document.querySelector(".profile-container"),
        document: document.documentElement,
      };
    },

    /**
     * Setup event listeners with event delegation
     */
    setupEventListeners: function () {
      const self = this;

      // Toggle dropdown on trigger click
      this.elements.trigger.addEventListener("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        self.toggle();
      });

      // Close dropdown when clicking outside
      document.addEventListener("click", function (e) {
        if (!self.elements.container.contains(e.target)) {
          self.close();
        }
      });

      // Close dropdown on escape key
      document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && self.isOpen) {
          self.close();
          self.elements.trigger.focus();
        }

        // Handle arrow key navigation
        if (self.isOpen) {
          self.handleKeyboardNavigation(e);
        }
      });

      // Close dropdown on resize (for responsive behavior)
      window.addEventListener("resize", function () {
        if (self.isOpen) {
          self.close();
        }
      });

      // Close dropdown when sidebar is toggled (for mobile)
      document.addEventListener("sidebarToggled", function () {
        if (self.isOpen) {
          self.close();
        }
      });
    },

    /**
     * Setup accessibility attributes
     */
    setupAccessibility: function () {
      if (this.elements.dropdown && this.elements.trigger) {
        this.elements.dropdown.setAttribute("aria-hidden", "true");
        this.elements.trigger.setAttribute("aria-expanded", "false");
      }
    },

    /**
     * Toggle dropdown
     */
    toggle: function () {
      if (this.isOpen) {
        this.close();
      } else {
        this.open();
      }
    },

    /**
     * Open dropdown
     */
    open: function () {
      // Close dashboard dropdowns first
      Dashboard.closeAllDropdowns();

      this.isOpen = true;
      this.elements.dropdown.setAttribute("aria-hidden", "false");
      this.elements.dropdown.setAttribute("aria-expanded", "true");
      this.elements.trigger.setAttribute("aria-expanded", "true");

      // Add active class for CSS animations
      this.elements.container.classList.add("active");
      this.elements.dropdown.classList.add("show");

      // Ensure dropdown is positioned correctly
      this.positionDropdown();

      // Focus first menu item for keyboard navigation
      setTimeout(() => {
        const firstMenuItem = this.elements.dropdown.querySelector("a");
        if (firstMenuItem) {
          firstMenuItem.focus();
        }
      }, 10);

      // Dispatch custom event
      this.dispatchEvent("profileDropdown:open");
    },

    /**
     * Close dropdown
     */
    close: function () {
      if (!this.isOpen) return;

      this.isOpen = false;
      this.elements.dropdown.setAttribute("aria-hidden", "true");
      this.elements.dropdown.setAttribute("aria-expanded", "false");
      this.elements.trigger.setAttribute("aria-expanded", "false");
      this.elements.container.classList.remove("active");
      this.elements.dropdown.classList.remove("show");

      // Dispatch custom event
      this.dispatchEvent("profileDropdown:close");
    },

    /**
     * Position dropdown correctly
     */
    positionDropdown: function () {
      const dropdown = this.elements.dropdown;
      const trigger = this.elements.trigger;
      const header = document.querySelector(".main-header");

      if (!dropdown || !trigger || !header) return;

      // Get positions
      const triggerRect = trigger.getBoundingClientRect();
      const headerRect = header.getBoundingClientRect();

      // Calculate position - always below header
      const topPosition = headerRect.bottom + 5; // 5px below header
      const rightPosition = window.innerWidth - triggerRect.right;

      // Apply fixed position
      dropdown.style.position = "fixed";
      dropdown.style.top = `${topPosition}px`;
      dropdown.style.right = `${rightPosition}px`;
      dropdown.style.left = "auto";
      dropdown.style.transform = "none";
      dropdown.style.zIndex = "9999"; // Ensure highest z-index
    },

    /**
     * Handle keyboard navigation
     */
    handleKeyboardNavigation: function (e) {
      const menuItems = Array.from(
        this.elements.dropdown.querySelectorAll("a")
      );
      const currentIndex = menuItems.indexOf(document.activeElement);

      switch (e.key) {
        case "ArrowDown":
          e.preventDefault();
          const nextIndex = (currentIndex + 1) % menuItems.length;
          menuItems[nextIndex].focus();
          break;

        case "ArrowUp":
          e.preventDefault();
          const prevIndex =
            currentIndex > 0 ? currentIndex - 1 : menuItems.length - 1;
          menuItems[prevIndex].focus();
          break;

        case "Home":
          e.preventDefault();
          menuItems[0].focus();
          break;

        case "End":
          e.preventDefault();
          menuItems[menuItems.length - 1].focus();
          break;

        case "Tab":
          if (!e.shiftKey && currentIndex === menuItems.length - 1) {
            e.preventDefault();
            this.close();
            // Move focus to next focusable element after trigger
            this.focusNextElement();
          }
          break;
      }
    },

    /**
     * Focus next element after trigger
     */
    focusNextElement: function () {
      const focusableElements = Array.from(
        document.querySelectorAll(
          'button, a, input, select, textarea, [tabindex]:not([tabindex="-1"])'
        )
      ).filter((el) => el.offsetParent !== null);

      const triggerIndex = focusableElements.indexOf(this.elements.trigger);
      const nextIndex = triggerIndex + 1;

      if (nextIndex < focusableElements.length) {
        focusableElements[nextIndex].focus();
      }
    },

    /**
     * Dispatch custom event
     */
    dispatchEvent: function (eventName) {
      const event = new CustomEvent(eventName, {
        bubbles: true,
        detail: { isOpen: this.isOpen },
      });
      if (this.elements.trigger) {
        this.elements.trigger.dispatchEvent(event);
      }
    },

    /**
     * Update dropdown position on scroll
     */
    updatePositionOnScroll: function () {
      if (this.isOpen) {
        this.positionDropdown();
      }
    },

    /**
     * Clean up event listeners
     */
    destroy: function () {
      // Remove all event listeners
      const self = this;

      // We need to store references to properly remove event listeners
      const handleDocumentClick = function (e) {
        if (!self.elements.container.contains(e.target)) {
          self.close();
        }
      };

      const handleKeyDown = function (e) {
        if (e.key === "Escape" && self.isOpen) {
          self.close();
          self.elements.trigger.focus();
        }

        if (self.isOpen) {
          self.handleKeyboardNavigation(e);
        }
      };

      const handleResize = function () {
        if (self.isOpen) {
          self.close();
        }
      };

      const handleScroll = function () {
        self.updatePositionOnScroll();
      };

      document.removeEventListener("click", handleDocumentClick);
      document.removeEventListener("keydown", handleKeyDown);
      window.removeEventListener("resize", handleResize);
      window.removeEventListener("scroll", handleScroll);

      if (this.elements.trigger) {
        this.elements.trigger.removeEventListener("click", function (e) {
          e.preventDefault();
          e.stopPropagation();
          self.toggle();
        });
      }

      // Reset state
      this.isOpen = false;
    },
  };

  // ============================================
  // INITIALIZATION
  // ============================================

  // Initialize on DOM ready
  $(document).ready(function () {
    Dashboard.init();
  });

  // Update profile dropdown position on scroll
  window.addEventListener("scroll", function () {
    ProfileDropdown.updatePositionOnScroll();
  });

  // Make components available globally for debugging
  window.Dashboard = Dashboard;
  window.ProfileDropdown = ProfileDropdown;

  // Expose helper functions
  window.dashboardHelpers = {
    toggleSidebar: function () {
      Dashboard.toggleSidebar();
    },
    toggleMobileSidebar: function () {
      Dashboard.toggleMobileSidebar();
    },
    toggleProfileDropdown: function () {
      ProfileDropdown.toggle();
    },
    closeAllDropdowns: function () {
      Dashboard.closeAllDropdowns();
      ProfileDropdown.close();
    },
    refreshDashboard: function () {
      Dashboard.checkScreenMode();
      Dashboard.setupTooltips();
    },
  };
})(jQuery);
