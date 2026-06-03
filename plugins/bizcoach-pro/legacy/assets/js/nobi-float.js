/**
 * BizCoach Map – Nobi Float Button JavaScript
 * Toggle panel, auto-refresh progress, attention animation
 * Đồng bộ pattern agent-helper.js (bizcity-brain-level)
 */

(function($) {
  'use strict';

  $(document).ready(function() {
    var $btn   = $('#btn-nobi-float-btn');
    var $panel = $('#nobi-panel');
    var $close = $('#nobi-panel-close');

    if (!$btn.length) return;

    // Toggle panel
    $btn.on('click', function(e) {
      e.preventDefault();
      e.stopPropagation();

      if ($panel.hasClass('active')) {
        $panel.removeClass('active');
      } else {
        $panel.addClass('active');
        refreshProgress();
      }
    });

    // Close panel
    $close.on('click', function(e) {
      e.preventDefault();
      $panel.removeClass('active');
    });

    // Click outside to close
    $(document).on('click', function(e) {
      if (!$(e.target).closest('#nobi-panel, #btn-nobi-float-btn').length) {
        $panel.removeClass('active');
      }
    });

    // Escape key
    $(document).on('keydown', function(e) {
      if (e.key === 'Escape') {
        $panel.removeClass('active');
      }
    });

    // Refresh every 60s
    setInterval(refreshProgress, 60000);

    // Attention animation when progress is low
    if (typeof bcNobiFloat !== 'undefined') {
      var overall = bcNobiFloat.progress.overall || 0;
      if (overall < 30) {
        $btn.css('animation', 'nobi-float-attention 1.5s ease-in-out infinite');
      }
    }
  });

  function refreshProgress() {
    if (typeof bcNobiFloat === 'undefined') return;

    $.ajax({
      url: bcNobiFloat.ajaxurl,
      type: 'POST',
      data: {
        action: 'bccm_nobi_progress',
        nonce: bcNobiFloat.nonce
      },
      success: function(res) {
        if (res.success && res.data) {
          updateUI(res.data);
        }
      }
    });
  }

  function updateUI(data) {
    var overall = data.overall || 0;
    var steps   = data.steps || [];

    // Progress bar
    $('.nobi-panel-progress-fill').css('width', overall + '%');
    $('.nobi-panel-progress-pct').text(overall + '%');

    // Badge
    if (overall < 100) {
      $('.btn-nobi-float-badge').text(overall + '%').show();
    } else {
      $('.btn-nobi-float-badge').hide();
    }

    // Header status
    var coacheeName = (data.coachee && data.coachee.full_name) ? data.coachee.full_name : 'Chưa chọn';
    var statusText = '⚠️ Cần bổ sung thêm';
    if (overall >= 100)     statusText = '✅ Hoàn thành';
    else if (overall >= 50) statusText = '🔄 Đang xây dựng...';
    $('.nobi-panel-header-sub').text(coacheeName + ' – ' + statusText);

    // Milestone levels
    steps.forEach(function(step, i) {
      var $ms = $('.nobi-panel-milestone').eq(i);
      if ($ms.length) {
        $ms.find('.nobi-ms-level').text(step.level + '%');

        // Update status class
        $ms.removeClass('nobi-ms-done nobi-ms-in-progress nobi-ms-pending')
           .addClass('nobi-ms-' + step.status);

        // Update dot
        var $dot = $ms.find('.nobi-ms-dot');
        $dot.empty();
        if (step.status === 'done') {
          $dot.html('<svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 6L5 9L10 3" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>');
        } else if (step.status === 'in-progress') {
          $dot.html('<div class="nobi-ms-pulse"></div>');
        }
      }
    });

    // Status cards
    if (steps.length >= 6) {
      $('.nobi-card-lifemap .nobi-card-value').text((steps[4].level || 0) + '%');
      $('.nobi-card-health .nobi-card-value').text((steps[3].level || 0) + '%');
      $('.nobi-card-calendar .nobi-card-value').text((steps[5].level || 0) + '%');
      $('.nobi-card-numerology .nobi-card-value').text((steps[1].level || 0) + '%');
    }

    // Attention animation
    if (overall < 30) {
      $('#btn-nobi-float-btn').css('animation', 'nobi-float-attention 1.5s ease-in-out infinite');
    } else {
      $('#btn-nobi-float-btn').css('animation', 'nobi-float-bounce 3s ease-in-out infinite');
    }
  }

})(jQuery);
