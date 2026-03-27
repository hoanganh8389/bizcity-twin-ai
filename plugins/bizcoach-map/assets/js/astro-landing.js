/**
 * BizCoach Map – Astrology Landing Page JavaScript
 *
 * Handles:
 * - Birth chart form submission (AJAX)
 * - Place autocomplete (OpenStreetMap Nominatim)
 * - Chart result display
 * - Registration flow toggle
 * - Nobi progress panel toggle
 * - Dialog management
 *
 * @package BizCoach_Map
 */
(function () {
  'use strict';

  /* =====================================================================
   * STATE
   * =====================================================================*/
  const state = {
    chartCreated: false,
    sessionToken: null,
  };

  /* =====================================================================
   * DOM READY
   * =====================================================================*/
  document.addEventListener('DOMContentLoaded', function () {
    initPlaceAutocomplete();
    initBirthChartForm();
    initRegisterToggle();
    initNobiPanel();
    initDialogClose();
  });

  /* =====================================================================
   * PLACE AUTOCOMPLETE (OpenStreetMap Nominatim)
   * =====================================================================*/
  function initPlaceAutocomplete() {
    const input = document.getElementById('alp-birth-place');
    const results = document.getElementById('alp-place-results');
    const latInput = document.getElementById('alp-latitude');
    const lngInput = document.getElementById('alp-longitude');
    const tzInput = document.getElementById('alp-timezone');

    if (!input || !results) return;

    let debounceTimer = null;

    input.addEventListener('input', function () {
      clearTimeout(debounceTimer);
      const query = this.value.trim();
      if (query.length < 3) {
        results.classList.remove('active');
        results.innerHTML = '';
        return;
      }
      debounceTimer = setTimeout(function () {
        fetchPlaces(query, results, input, latInput, lngInput, tzInput);
      }, 400);
    });

    // Close dropdown on outside click
    document.addEventListener('click', function (e) {
      if (!input.contains(e.target) && !results.contains(e.target)) {
        results.classList.remove('active');
      }
    });
  }

  function fetchPlaces(query, results, input, latInput, lngInput, tzInput) {
    const url = 'https://nominatim.openstreetmap.org/search?format=json&q=' +
                encodeURIComponent(query) + '&limit=5&accept-language=vi';

    fetch(url, {
      headers: { 'User-Agent': 'BizCoachMap/1.0' }
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      results.innerHTML = '';
      if (!data || data.length === 0) {
        results.classList.remove('active');
        return;
      }
      data.forEach(function (place) {
        const item = document.createElement('div');
        item.className = 'astro-lp-place-item';
        item.textContent = place.display_name;
        item.addEventListener('click', function () {
          input.value = place.display_name;
          if (latInput) latInput.value = parseFloat(place.lat).toFixed(4);
          if (lngInput) lngInput.value = parseFloat(place.lon).toFixed(4);
          // Rough timezone estimate from longitude
          if (tzInput) {
            const tz = Math.round(parseFloat(place.lon) / 15);
            tzInput.value = tz;
          }
          results.classList.remove('active');
        });
        results.appendChild(item);
      });
      results.classList.add('active');
    })
    .catch(function () {
      results.classList.remove('active');
    });
  }

  /* =====================================================================
   * BIRTH CHART FORM SUBMISSION
   * =====================================================================*/
  function initBirthChartForm() {
    const form = document.getElementById('alp-chart-form');
    if (!form) return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();

      const btn = form.querySelector('.astro-lp-btn-primary');
      const loading = document.getElementById('alp-loading');
      const formSection = document.getElementById('alp-form-section');

      // Validate
      const fullName = form.querySelector('[name="full_name"]').value.trim();
      const dob = form.querySelector('[name="dob"]').value.trim();
      const birthTime = form.querySelector('[name="birth_time"]').value.trim();
      const birthPlace = form.querySelector('[name="birth_place"]').value.trim();

      if (!fullName || !dob) {
        showAlert('Vui lòng nhập Họ tên và Ngày sinh.');
        return;
      }

      // Collect form data
      const formData = new FormData(form);
      formData.append('action', 'bccm_create_guest_chart');
      formData.append('nonce', bccmAstroLanding.nonce);

      // Show loading
      btn.disabled = true;
      if (loading) loading.classList.add('active');

      fetch(bccmAstroLanding.ajaxUrl, {
        method: 'POST',
        body: formData,
      })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (loading) loading.classList.remove('active');
        btn.disabled = false;

        if (data.success) {
          state.chartCreated = true;
          state.sessionToken = data.data.session_token || '';

          // Show result
          displayChartResult(data.data);

          // Update progress steps
          markStepDone(1);
          markStepActive(2);

          // Scroll to result
          const resultEl = document.getElementById('alp-result');
          if (resultEl) {
            resultEl.classList.add('active');
            resultEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }

          // Show CTA
          const ctaEl = document.getElementById('alp-cta-section');
          if (ctaEl) ctaEl.style.display = 'block';

        } else {
          showAlert(data.data || 'Có lỗi xảy ra. Vui lòng thử lại.');
        }
      })
      .catch(function (err) {
        if (loading) loading.classList.remove('active');
        btn.disabled = false;
        showAlert('Lỗi kết nối. Vui lòng thử lại.');
        console.error(err);
      });
    });
  }

  /* =====================================================================
   * DISPLAY CHART RESULT
   * =====================================================================*/
  function displayChartResult(data) {
    const container = document.getElementById('alp-result-content');
    if (!container) return;

    let html = '';

    // Name & zodiac badge
    if (data.zodiac_vi || data.zodiac_sign) {
      const badgeEl = document.getElementById('alp-zodiac-badge');
      if (badgeEl) {
        badgeEl.textContent = data.zodiac_vi || data.zodiac_sign;
        badgeEl.style.display = 'inline-block';
      }
    }

    // User info header
    html += '<div class="astro-lp-user-info">';
    html += '<p><strong>' + escHtml(data.full_name || '') + '</strong></p>';
    html += '<p style="color:#9ca3af;font-size:14px">Sinh ngày ' + escHtml(formatDate(data.dob)) + ' lúc ' + escHtml(data.birth_time || '12:00') + '</p>';
    if (data.birth_place) {
      html += '<p style="color:#9ca3af;font-size:14px">Tại: ' + escHtml(data.birth_place) + '</p>';
    }
    html += '</div>';

    // Big 3 - Sun, Moon, Ascendant
    if (data.sun_sign || data.moon_sign || data.asc_sign) {
      html += '<div class="astro-lp-big3">';
      if (data.sun_sign) {
        var sunVi = getZodiacVi(data.sun_sign);
        html += '<div class="astro-lp-big3-card">' +
                '<div class="big3-icon">☀️</div>' +
                '<div class="big3-label">Mặt Trời</div>' +
                '<div class="big3-sign">' + escHtml(sunVi || data.sun_sign) + '</div>' +
                '</div>';
      }
      if (data.moon_sign) {
        var moonVi = getZodiacVi(data.moon_sign);
        html += '<div class="astro-lp-big3-card">' +
                '<div class="big3-icon">🌙</div>' +
                '<div class="big3-label">Mặt Trăng</div>' +
                '<div class="big3-sign">' + escHtml(moonVi || data.moon_sign) + '</div>' +
                '</div>';
      }
      if (data.asc_sign) {
        var ascVi = getZodiacVi(data.asc_sign);
        html += '<div class="astro-lp-big3-card">' +
                '<div class="big3-icon">⬆️</div>' +
                '<div class="big3-label">Cung Mọc</div>' +
                '<div class="big3-sign">' + escHtml(ascVi || data.asc_sign) + '</div>' +
                '</div>';
      }
      html += '</div>';
    }

    // Chart wheel image
    if (data.chart_url) {
      html += '<div class="astro-lp-chart-wheel">';
      html += '<h3 style="margin-bottom:12px">🔮 Bản Đồ Sao Natal</h3>';
      html += '<img src="' + escHtml(data.chart_url) + '" alt="Natal Wheel Chart" style="max-width:100%;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,0.3)" loading="lazy" />';
      html += '</div>';
    }

    // Planet positions (limited preview)
    if (data.planets && data.planets.length > 0) {
      html += '<h3 style="margin:24px 0 12px">🪐 Vị Trí Các Hành Tinh</h3>';
      html += '<div class="astro-lp-planet-grid">';
      var previewPlanets = data.planets.slice(0, 6);
      previewPlanets.forEach(function (p) {
        var retroIcon = p.is_retro ? ' <span style="color:#ef4444" title="Nghịch hành">℞</span>' : '';
        html += '<div class="astro-lp-planet-tile">' +
                '  <div class="planet-name">' + escHtml(p.name_vi || p.name) + retroIcon + '</div>' +
                '  <div class="planet-sign">' + (p.sign_symbol || '') + ' ' + escHtml(p.sign_vi || p.sign) + '</div>' +
                '  <div class="planet-degree">' + escHtml(p.degree || '') + '</div>' +
                '</div>';
      });
      html += '</div>';
    }

    // Blurred full report teaser (for more planets)
    if (data.planets && data.planets.length > 6) {
      html += '<div class="astro-lp-preview-blur">';
      html += '<div class="blur-content">';
      html += '<div class="astro-lp-planet-grid">';
      data.planets.slice(6).forEach(function (p) {
        html += '<div class="astro-lp-planet-tile">' +
                '  <div class="planet-name">' + escHtml(p.name_vi || p.name) + '</div>' +
                '  <div class="planet-sign">' + escHtml(p.sign_vi || p.sign) + '</div>' +
                '</div>';
      });
      html += '</div></div>';
      html += '<div class="blur-overlay">';
      if (bccmAstroLanding.isLoggedIn) {
        html += '<div class="blur-overlay-text">✨ Bản đồ sao đầy đủ đã lưu trong tài khoản của bạn</div>' +
                '<a href="' + escHtml(bccmAstroLanding.myAccountUrl || '/my-account/') + '" class="astro-lp-btn-primary">🗺️ Xem Bản Đồ Đầy Đủ</a>';
      } else {
        html += '<div class="blur-overlay-text">🔒 Đăng ký thành viên để xem đầy đủ & tải PDF</div>' +
                '<button class="astro-lp-btn-primary" onclick="scrollToRegister()">✨ Đăng Ký Ngay</button>';
      }
      html += '</div>';
      html += '</div>';
    }

    // Registration CTA
    if (!bccmAstroLanding.isLoggedIn) {
      html += '<div class="astro-lp-register-cta">';
      html += '<p style="margin:16px 0;color:#d1d5db;text-align:center">📋 Đăng ký thành viên để:</p>';
      html += '<ul style="color:#9ca3af;font-size:14px;margin:0 0 16px 20px;line-height:1.8">';
      html += '<li>✅ Xem bản đồ chiêm tinh chi tiết</li>';
      html += '<li>✅ Tải PDF bản đồ sao về máy</li>';
      html += '<li>✅ Nhận AI Agent trợ lý cá nhân</li>';
      html += '</ul>';
      html += '</div>';
    }

    container.innerHTML = html;
  }

  function scrollToRegister() {
    var regWrap = document.getElementById('alp-register-wrap');
    if (regWrap) {
      regWrap.classList.add('active');
      var registerTab = regWrap.querySelector('.bizcity-auth-tab[data-tab="register"]');
      if (registerTab) registerTab.click();
      regWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  function formatDate(dateStr) {
    if (!dateStr) return '';
    var parts = dateStr.split('-');
    if (parts.length !== 3) return dateStr;
    return parts[2] + '/' + parts[1] + '/' + parts[0];
  }

  function getZodiacVi(signEn) {
    var map = {
      'Aries': 'Bạch Dương', 'Taurus': 'Kim Ngưu', 'Gemini': 'Song Tử',
      'Cancer': 'Cự Giải', 'Leo': 'Sư Tử', 'Virgo': 'Xử Nữ',
      'Libra': 'Thiên Bình', 'Scorpio': 'Bọ Cạp', 'Sagittarius': 'Nhân Mã',
      'Capricorn': 'Ma Kết', 'Aquarius': 'Bảo Bình', 'Pisces': 'Song Ngư'
    };
    return map[signEn] || signEn;
  }

  /* =====================================================================
   * REGISTER TOGGLE
   * =====================================================================*/
  function initRegisterToggle() {
    // CTA button opens registration form
    document.querySelectorAll('[data-action="show-register"]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        const regWrap = document.getElementById('alp-register-wrap');
        if (regWrap) {
          regWrap.classList.add('active');
          
          // Activate register tab in form-login.php
          const registerTab = regWrap.querySelector('.bizcity-auth-tab[data-tab="register"]');
          if (registerTab) {
            registerTab.click();
          }
          
          regWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      });
    });
  }

  /* =====================================================================
   * NOBI FLOAT PANEL
   * =====================================================================*/
  function initNobiPanel() {
    const btn = document.getElementById('nobi-fe-float-btn');
    const panel = document.getElementById('nobi-fe-panel');
    const closeBtn = document.getElementById('nobi-fe-panel-close');

    if (!btn || !panel) return;

    btn.addEventListener('click', function () {
      panel.classList.toggle('active');
    });

    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        panel.classList.remove('active');
      });
    }

    // Close on outside click
    document.addEventListener('click', function (e) {
      if (panel.classList.contains('active') && !panel.contains(e.target) && !btn.contains(e.target)) {
        panel.classList.remove('active');
      }
    });
  }

  /* =====================================================================
   * DIALOG MANAGEMENT
   * =====================================================================*/
  function initDialogClose() {
    document.querySelectorAll('[data-dialog-close]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const overlay = this.closest('.astro-lp-dialog-overlay');
        if (overlay) overlay.classList.remove('active');
      });
    });
  }

  /* =====================================================================
   * STEP PROGRESS HELPERS
   * =====================================================================*/
  function markStepDone(stepNum) {
    const step = document.querySelector('.astro-lp-step[data-step="' + stepNum + '"]');
    if (step) {
      step.classList.remove('active', 'pending');
      step.classList.add('done');
      const statusEl = step.querySelector('.astro-lp-step-status');
      if (statusEl) statusEl.textContent = '✅ Hoàn thành';
      const numEl = step.querySelector('.astro-lp-step-num');
      if (numEl) numEl.textContent = '✓';
    }
  }

  function markStepActive(stepNum) {
    const step = document.querySelector('.astro-lp-step[data-step="' + stepNum + '"]');
    if (step) {
      step.classList.remove('pending', 'done');
      step.classList.add('active');
      const statusEl = step.querySelector('.astro-lp-step-status');
      if (statusEl) statusEl.textContent = '⏳ Đang thực hiện';
    }
  }

  /* =====================================================================
   * UTILITIES
   * =====================================================================*/
  function escHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  function showAlert(msg) {
    // Use SweetAlert2 if available, otherwise native alert
    if (typeof Swal !== 'undefined') {
      Swal.fire({
        icon: 'warning',
        title: 'Thông báo',
        text: msg,
        confirmButtonColor: '#7c3aed',
        background: '#1a1b23',
        color: '#e2e8f0',
      });
    } else {
      alert(msg);
    }
  }

  // Expose to global scope for onclick handlers
  window.scrollToRegister = scrollToRegister;

})();
