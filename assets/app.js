(() => {
  "use strict";

  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const form = document.getElementById("searchForm");
  const project = document.getElementById("project_id");
  const container = document.getElementById("dynamicFields");

  // ===== Toast 提示（公众页面用） =====
  function showPublicToast(message, type) {
    type = type || "error";
    var existing = document.querySelector(".pub-toast");
    if (existing) existing.remove();
    var toast = document.createElement("div");
    toast.className = "pub-toast pub-toast-" + type;
    toast.setAttribute("role", type === "error" ? "alert" : "status");
    toast.textContent = message;
    document.body.appendChild(toast);
    var remove = function() {
      toast.classList.add("is-leaving");
      window.setTimeout(function() { toast.remove(); }, reduceMotion ? 0 : 180);
    };
    window.setTimeout(remove, type === "error" ? 4500 : 3200);
  }

  // ===== 认证 Toast =====
  document.querySelectorAll("[data-auth-toast]").forEach(function(toast) {
    var remove = function() {
      toast.classList.add("is-leaving");
      window.setTimeout(function() { toast.remove(); }, reduceMotion ? 0 : 180);
    };
    toast.querySelector("button") && toast.querySelector("button").addEventListener("click", remove);
    window.setTimeout(remove, toast.classList.contains("auth-toast-error") ? 7000 : 4800);
  });

  // ===== 注册密码验证 =====
  var registerPassword = document.getElementById("register_password");
  var registerConfirmation = document.getElementById("register_password_confirmation");
  var validateConfirmation = function() {
    if (!(registerPassword instanceof HTMLInputElement) || !(registerConfirmation instanceof HTMLInputElement)) return;
    registerConfirmation.setCustomValidity(registerConfirmation.value !== registerPassword.value ? "两次输入的密码不一致。" : "");
  };
  registerPassword && registerPassword.addEventListener("input", validateConfirmation);
  registerConfirmation && registerConfirmation.addEventListener("input", validateConfirmation);

  // ===== Turnstile 人机验证 =====
  document.querySelectorAll("[data-turnstile-widget]").forEach(function(widget) {
    var shell = widget.closest(".turnstile-shell");
    var error = shell && shell.querySelector("[data-turnstile-error]");
    var status = shell && shell.querySelector("[data-turnstile-status]");
    var turnstileForm = widget.closest("form[data-turnstile-form]");
    var submitButton = turnstileForm && (turnstileForm.querySelector("[data-auth-submit]") || turnstileForm.querySelector('button[type="submit"]'));
    var verificationRequired = turnstileForm && turnstileForm.dataset.turnstileRequired === "true";
    var pendingMessage = (turnstileForm && turnstileForm.dataset.turnstilePendingMessage) || "请先完成人机验证，再继续。";
    var successMessage = (turnstileForm && turnstileForm.dataset.turnstileSuccessMessage) || "人机验证已完成，可以继续。";
    var attempts = 0;
    var setVerificationState = function(verified, message) {
      message = message || "";
      if (turnstileForm instanceof HTMLFormElement) turnstileForm.dataset.turnstileVerified = verified ? "true" : "false";
      if (verificationRequired && submitButton instanceof HTMLButtonElement) {
        submitButton.disabled = !verified;
        submitButton.setAttribute("aria-disabled", verified ? "false" : "true");
        submitButton.classList.toggle("is-turnstile-locked", !verified);
      }
      if (status instanceof HTMLElement) {
        status.textContent = message || (verified ? successMessage : pendingMessage);
        status.classList.toggle("is-verified", verified);
      }
    };
    var showErr = function(msg) { setVerificationState(false, "人机验证未完成，请重新验证。"); if (error) { error.textContent = msg; error.hidden = false; } };
    setVerificationState(false);
    var render = function() {
      if (!window.turnstile) { attempts += 1; if (attempts < 50) window.setTimeout(render, 100); else showErr("人机验证加载失败，请检查网络后刷新页面。"); return; }
      try {
        window.turnstile.render(widget, {
          sitekey: widget.dataset.siteKey || "", theme: widget.dataset.theme || "auto", size: widget.dataset.size || "normal", action: widget.dataset.action || "",
          "error-callback": function() { showErr("人机验证加载失败，请检查网络后刷新页面。"); },
          "expired-callback": function() { showErr("人机验证已过期，请重新验证。"); },
          "timeout-callback": function() { showErr("人机验证已超时，请重新验证。"); },
          callback: function(token) { if (error) error.hidden = true; setVerificationState(typeof token === "string" && token !== ""); }
        });
      } catch (_) { showErr("人机验证加载失败，请检查网络后刷新页面。"); }
    };
    render();
  });

  // ===== 动态查询字段 =====
  if (form && project && container) {
    var schemas = {};
    try { schemas = JSON.parse(form.dataset.schemas || "{}"); } catch (_) { schemas = {}; }

    var renderFields = function() {
      var fields = schemas[String(project.value)] || [];
      var fragment = document.createDocumentFragment();
      for (var i = 0; i < fields.length; i++) {
        var field = fields[i];
        var group = document.createElement("div"); group.className = "form-group";
        var label = document.createElement("label"); label.htmlFor = "field_" + field.key; label.textContent = field.label;
        var input;
        if (field.type === "select") {
          input = document.createElement("select"); var empty = document.createElement("option"); empty.value = ""; empty.textContent = "请选择" + field.label; input.appendChild(empty);
          for (var j = 0; j < (field.options || []).length; j++) { var node = document.createElement("option"); node.value = field.options[j]; node.textContent = field.options[j]; input.appendChild(node); }
        } else {
          input = document.createElement("input"); input.type = ["password", "number", "date"].includes(field.type) ? field.type : "text";
          if (field.type === "number") input.step = "any";
        }
        input.id = "field_" + field.key; input.name = "conditions[" + field.key + "]"; input.className = "form-control";
        input.required = Boolean(field.required); input.placeholder = field.placeholder || "";
        group.append(label, input); fragment.appendChild(group);
      }
      container.replaceChildren(fragment);
      container.classList.remove("fields-leaving"); container.classList.add("fields-entering");
      window.setTimeout(function() { container.classList.remove("fields-entering"); }, reduceMotion ? 0 : 240);
      var firstInput = container.querySelector(".form-control");
      if (firstInput instanceof HTMLElement) firstInput.focus({ preventScroll: true });
    };
    project.addEventListener("change", function() {
      if (reduceMotion) { renderFields(); return; }
      container.classList.add("fields-leaving"); window.setTimeout(renderFields, 130);
    });
  }

  // ===== 提交按钮状态 =====
  document.addEventListener("submit", function(event) {
    var sf = event.target;
    if (!(sf instanceof HTMLFormElement)) return;
    validateConfirmation();
    if (sf.dataset.turnstileRequired === "true" && sf.dataset.turnstileVerified !== "true") {
      event.preventDefault();
      var st = sf.querySelector("[data-turnstile-status]");
      if (st instanceof HTMLElement) { st.textContent = sf.dataset.turnstilePendingMessage || "请先完成人机验证，再继续。"; st.classList.remove("is-verified"); st.focus({ preventScroll: false }); }
      return;
    }
    var btn = event.submitter instanceof HTMLButtonElement ? event.submitter : sf.querySelector('button[type="submit"]');
    if (!(btn instanceof HTMLButtonElement)) return;
    btn.classList.add("is-loading"); btn.disabled = true; btn.setAttribute("aria-busy", "true");
  });

  // ===== 结果页面：保存截图功能（纯 Canvas 渲染，无外部依赖） =====
  var captureBtn = document.querySelector("[data-save-result-image]");
  if (captureBtn instanceof HTMLButtonElement) initResultScreenshot(captureBtn);

  function initResultScreenshot(button) {
    button.addEventListener("click", function() { saveResultAsImage(button); });
  }

  async function saveResultAsImage(button) {
    if (button.disabled) return;
    button.classList.add("is-loading"); button.disabled = true; button.setAttribute("aria-busy", "true");
    button.innerHTML = "正在生成...";

    try {
      await document.fonts.ready;
      var canvas = buildResultCanvas();
      if (!canvas) throw new Error("截图渲染失败");
      var blob = await canvasToBlob(canvas);
      var captureArea = document.getElementById("resultCapture");
      var safeName = (captureArea && captureArea.dataset.safeFilename) || "成绩查询结果";
      var now = new Date();
      var dateStr = now.getFullYear() + pad(now.getMonth()+1) + pad(now.getDate()) + "_" + pad(now.getHours()) + pad(now.getMinutes()) + pad(now.getSeconds());
      var filename = safeName + "_查询结果_" + dateStr + ".png";
      var downloaded = await tryDownload(blob, filename);
      if (downloaded) { showPublicToast("截图已生成", "success"); }
      else {
        var url = URL.createObjectURL(blob);
        var w = window.open(url, "_blank");
        showPublicToast(w ? "请长按图片保存到相册" : "请长按图片保存", "warning");
        if (!w) window.location.href = url;
        window.setTimeout(function() { URL.revokeObjectURL(url); }, 30000);
      }
    } catch (e) {
      console.error("截图失败: " + (e && e.message ? e.message : e));
      showPublicToast("截图生成失败，请稍后重试。", "error");
    } finally {
      button.classList.remove("is-loading"); button.disabled = false; button.removeAttribute("aria-busy");
      button.innerHTML = '<svg class="result-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7,10 12,15 17,10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>保存截图';
    }
  }

  function buildResultCanvas() {
    var captureArea = document.getElementById("resultCapture");
    if (!captureArea) return null;

    // 计算渲染参数
    var scale = Math.min(window.devicePixelRatio || 1, 2); // 最大2倍清晰度
    var width = 750; // 固定渲染宽度，确保截图一致性
    if (window.innerWidth < 420) width = 360; // 小屏幕适配

    // 先测算内容高度
    var estHeight = estimateContentHeight(captureArea, width);
    var height = Math.max(estHeight, 200);

    // 限制最大尺寸
    var maxDim = 5000;
    if (width * scale > maxDim) scale = maxDim / width;
    if (height * scale > maxDim) scale = Math.min(scale, maxDim / height);

    var canvas = document.createElement("canvas");
    canvas.width = Math.floor(width * scale);
    canvas.height = Math.floor(height * scale);
    var ctx = canvas.getContext("2d");
    if (!ctx) return null;
    ctx.scale(scale, scale);

    // 绘制白色背景
    ctx.fillStyle = "#ffffff";
    ctx.fillRect(0, 0, width, height);

    var y = 0;
    var padX = 24;
    var heroPadX = 28;
    var borderRadius = 18;

    // ===== 头部：蓝绿渐变 =====
    var heroEl = captureArea.querySelector(".result-hero");
    var heroH = 130;
    if (heroEl) {
      // 渐变背景
      var heroGrad = ctx.createLinearGradient(0, 0, width, heroH);
      heroGrad.addColorStop(0, "#1677ff");
      heroGrad.addColorStop(1, "#0ea5e9");
      ctx.fillStyle = heroGrad;
      ctx.beginPath();
      ctx.moveTo(borderRadius, 0);
      ctx.lineTo(width - borderRadius, 0);
      ctx.quadraticCurveTo(width, 0, width, borderRadius);
      ctx.lineTo(width, heroH);
      ctx.lineTo(0, heroH);
      ctx.lineTo(0, borderRadius);
      ctx.quadraticCurveTo(0, 0, borderRadius, 0);
      ctx.closePath();
      ctx.fill();

      // 查询成功徽章
      var badgeY = 28;
      ctx.fillStyle = "rgba(255,255,255,0.18)";
      ctx.beginPath();
      ctx.roundRect(width/2 - 60, badgeY, 120, 28, 14);
      ctx.fill();
      ctx.fillStyle = "#ffffff";
      ctx.font = "600 13px 'PingFang SC','Microsoft YaHei',system-ui,sans-serif";
      ctx.textAlign = "center";
      ctx.fillText("✓ 查询成功", width/2, badgeY + 19);
      // 项目名称
      var nameEl = heroEl.querySelector(".result-project-name");
      var name = nameEl ? nameEl.textContent.trim() : "";
      if (name) {
        ctx.font = "700 20px 'PingFang SC','Microsoft YaHei',system-ui,sans-serif";
        ctx.fillText(name, width/2, badgeY + 62);
      }
      // 提示文字
      var noticeEl = heroEl.querySelector(".result-hero-notice");
      var notice = noticeEl ? noticeEl.textContent.trim() : "";
      if (notice) {
        ctx.font = "400 13px 'PingFang SC','Microsoft YaHei',system-ui,sans-serif";
        ctx.fillStyle = "rgba(255,255,255,0.88)";
        ctx.fillText(notice, width/2, badgeY + 88);
      }
      y = heroH;
    }
    ctx.textAlign = "start";

    // ===== 结果字段区域 =====
    var contentEl = captureArea.querySelector(".result-content");
    var fields = contentEl ? contentEl.querySelectorAll(".result-field") : [];
    if (fields.length > 0) {
      var fieldY = y + 1;
      var colW = width / 2;
      for (var i = 0; i < fields.length; i++) {
        var col = i % 2;
        var row = Math.floor(i / 2);
        var fx = col * colW;
        var fy = fieldY + row * 62;

        var labelEl = fields[i].querySelector(".result-field-label");
        var valueEl = fields[i].querySelector(".result-field-value");
        var label = labelEl ? labelEl.textContent.trim() : "";
        var value = valueEl ? valueEl.textContent.trim() : "";

        // 分隔线
        if (col === 1) {
          ctx.strokeStyle = "#f0f3f7"; ctx.lineWidth = 1;
          ctx.beginPath(); ctx.moveTo(colW, fy + 4); ctx.lineTo(colW, fy + 54); ctx.stroke();
        }
        if (row > 0) {
          ctx.strokeStyle = "#f0f3f7"; ctx.lineWidth = 1;
          ctx.beginPath(); ctx.moveTo(fx + 20, fy - 4); ctx.lineTo(fx + colW - 20, fy - 4); ctx.stroke();
        }

        // 标签
        ctx.fillStyle = "#667085";
        ctx.font = "500 11px 'PingFang SC','Microsoft YaHei',system-ui,sans-serif";
        ctx.fillText(label, fx + 20, fy + 22);
        // 值
        ctx.fillStyle = "#243447";
        ctx.font = "650 15px 'PingFang SC','Microsoft YaHei',system-ui,sans-serif";
        var maxTextW = colW - 40;
        var text = truncateText(ctx, value, maxTextW);
        ctx.fillText(text, fx + 20, fy + 48);
      }
      y = fieldY + Math.ceil(fields.length / 2) * 62;
    }

    // ===== 提示框 =====
    var noticeBox = captureArea.querySelector(".result-notice-box");
    if (noticeBox) {
      var noticeText = noticeBox.textContent.trim();
      if (noticeText) {
        y += 4;
        ctx.fillStyle = "#f0f7ff"; ctx.strokeStyle = "#d4e8ff"; ctx.lineWidth = 1;
        ctx.beginPath(); ctx.roundRect(16, y, width - 32, 46, 12); ctx.fill(); ctx.stroke();
        ctx.fillStyle = "#3a6fb5";
        ctx.font = "400 12px 'PingFang SC','Microsoft YaHei',system-ui,sans-serif";
        ctx.fillText(noticeText, 34, y + 28);
        y += 58;
      }
    }

    // ===== 底部 =====
    var footerEl = captureArea.querySelector(".result-capture-footer");
    var footerH = 60;
    if (footerEl) {
      ctx.strokeStyle = "#f0f3f7"; ctx.lineWidth = 1;
      ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(width, y); ctx.stroke();
      y += 4;
      var fl = footerEl.querySelector(".result-footer-line");
      var fb = footerEl.querySelector(".result-footer-brand");
      var flText = fl ? fl.textContent.trim() : "";
      var fbText = fb ? fb.textContent.trim() : "";
      ctx.fillStyle = "#667085";
      ctx.font = "400 11px 'PingFang SC','Microsoft YaHei',system-ui,sans-serif";
      ctx.textAlign = "center";
      if (flText) { ctx.fillText(flText, width/2, y + 20); }
      ctx.fillStyle = "#b0b9c4";
      ctx.font = "500 10px 'PingFang SC','Microsoft YaHei',system-ui,sans-serif";
      if (fbText) { ctx.fillText(fbText, width/2, y + 36); }
      ctx.textAlign = "start";
      y = y + footerH;
    }

    // 裁剪 canvas 到实际高度
    if (y < height) {
      var cropCanvas = document.createElement("canvas");
      cropCanvas.width = canvas.width;
      cropCanvas.height = Math.floor(y * scale);
      var cropCtx = cropCanvas.getContext("2d");
      cropCtx.drawImage(canvas, 0, 0, canvas.width, Math.floor(y * scale), 0, 0, canvas.width, Math.floor(y * scale));
      return cropCanvas;
    }

    return canvas;
  }

  function estimateContentHeight(captureArea, width) {
    var h = 130; // hero
    var fields = captureArea.querySelectorAll(".result-field");
    h += Math.ceil(fields.length / 2) * 62;
    if (captureArea.querySelector(".result-notice-box")) h += 62;
    h += 70; // footer + padding
    return Math.max(h, 300);
  }

  function truncateText(ctx, text, maxWidth) {
    if (!text) return "";
    if (ctx.measureText(text).width <= maxWidth) return text;
    while (text.length > 1 && ctx.measureText(text + "...").width > maxWidth) { text = text.slice(0, -1); }
    return text + "...";
  }

  function canvasToBlob(canvas) {
    return new Promise(function(resolve, reject) {
      try { canvas.toBlob(function(blob) { if (blob) resolve(blob); else reject(new Error("toBlob returned null")); }, "image/png"); }
      catch (e) { reject(e); }
    });
  }

  function tryDownload(blob, filename) {
    return new Promise(function(resolve) {
      try {
        var url = URL.createObjectURL(blob);
        var a = document.createElement("a"); a.href = url; a.download = filename; a.style.display = "none";
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        window.setTimeout(function() { URL.revokeObjectURL(url); resolve(true); }, 100);
      } catch (e) { resolve(false); }
    });
  }

  function pad(n) { return n < 10 ? "0" + n : String(n); }


  // ===== 邮箱验证码 =====
  var emailCodeTrigger = document.querySelector("[data-email-code-trigger]");
  if (emailCodeTrigger instanceof HTMLButtonElement) {
    initEmailVerification(emailCodeTrigger);
  }

  function initEmailVerification(triggerBtn) {
    var emailFieldId = triggerBtn.dataset.emailField;
    var emailField = document.getElementById(emailFieldId || "register_email");
    var codeInput = document.getElementById("register_email_code");
    var tokenField = document.getElementById("email_code_token");
    var emailCodeGroup = document.getElementById("email_code_group");
    var turnstileDialog = document.getElementById("turnstileEmailDialog");
    var csrfField = document.querySelector("input[name=\"csrf_token\"]");
    var countdownTimer = null;
    var turnstileWidgetId = null;
    var turnstileResolved = false;
    var pendingEmail = "";
    var originalBtnText = triggerBtn.textContent || "获取验证码";

    // Before form submission, verify token if email code is enabled
    var regForm = document.querySelector(".registration-form");
    if (regForm instanceof HTMLFormElement) {
      regForm.addEventListener("submit", function(e) {
        var codeGroup = document.getElementById("email_code_group");
        if (codeGroup && codeGroup.style.display !== "none") {
          if (tokenField instanceof HTMLInputElement && !tokenField.value) {
            e.preventDefault();
            showAuthToast("请重新获取邮箱验证码", "error");
            return false;
          }
        }
      });
    }

    // Restore countdown from server if available
    if (triggerBtn.dataset.nextAvailableAt) {
      var nextAt = parseInt(triggerBtn.dataset.nextAvailableAt, 10);
      if (nextAt > 0) {
        var remaining = Math.max(0, nextAt - Math.floor(Date.now() / 1000));
        if (remaining > 0) {
          startCountdown(remaining);
        }
      }
    }

    // Cancel countdown when email changes
    if (emailField instanceof HTMLInputElement) {
      emailField.addEventListener("input", function() {
        if (countdownTimer) {
          clearInterval(countdownTimer);
          countdownTimer = null;
          triggerBtn.disabled = false;
          triggerBtn.setAttribute("aria-disabled", "false");
          triggerBtn.textContent = originalBtnText;
          triggerBtn.removeAttribute("data-next-available-at");
          if (codeInput instanceof HTMLInputElement) {
            codeInput.value = "";
          }
          if (tokenField instanceof HTMLInputElement) {
            tokenField.value = "";
          }
        }
        if (codeInput instanceof HTMLInputElement) {
          codeInput.value = "";
        }
        if (tokenField instanceof HTMLInputElement) {
          tokenField.value = "";
        }
      });
    }

    function startCountdown(seconds) {
      triggerBtn.disabled = true;
      triggerBtn.setAttribute("aria-disabled", "true");
      updateCountdownDisplay(seconds);

      countdownTimer = setInterval(function() {
        seconds--;
        if (seconds <= 0) {
          clearInterval(countdownTimer);
          countdownTimer = null;
          triggerBtn.disabled = false;
          triggerBtn.setAttribute("aria-disabled", "false");
          triggerBtn.textContent = originalBtnText;
          triggerBtn.removeAttribute("data-next-available-at");
        } else {
          updateCountdownDisplay(seconds);
        }
      }, 1000);
    }

    function updateCountdownDisplay(seconds) {
      triggerBtn.textContent = seconds + "秒后重新获取";
    }

    function showAuthToast(message, type) {
      type = type || "error";
      var existing = document.querySelector(".auth-toast");
      if (existing) existing.remove();
      var toast = document.createElement("div");
      toast.className = "auth-toast auth-toast-" + type;
      toast.setAttribute("role", type === "error" ? "alert" : "status");
      toast.innerHTML = "<strong>" + (type === "error" ? "错误" : "成功") + "</strong><span>" + escapeHtml(message) + "</span><button type=\"button\" aria-label=\"关闭\">&times;</button>";
      document.body.appendChild(toast);
      var remove = function() {
        toast.classList.add("is-leaving");
        window.setTimeout(function() { toast.remove(); }, 180);
      };
      toast.querySelector("button") && toast.querySelector("button").addEventListener("click", remove);
      window.setTimeout(remove, type === "error" ? 7000 : 4800);
    }

    function escapeHtml(str) {
      var div = document.createElement("div");
      div.appendChild(document.createTextNode(str));
      return div.innerHTML;
    }

    function getCsrfToken() {
      if (csrfField instanceof HTMLInputElement) return csrfField.value;
      var f = document.querySelector("input[name=\"csrf_token\"]");
      return f instanceof HTMLInputElement ? f.value : "";
    }

    function openTurnstileDialog() {
      if (!(turnstileDialog instanceof HTMLDialogElement)) {
        // No dialog support, try direct send
        sendCodeRequest("");
        return;
      }

      if (!emailField || !(emailField instanceof HTMLInputElement)) {
        showAuthToast("请先输入正确的邮箱地址", "error");
        return;
      }

      var email = emailField.value.trim();
      if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showAuthToast("请先输入正确的邮箱地址", "error");
        return;
      }

      pendingEmail = email;
      turnstileResolved = false;
      resetTurnstileWidget();

      turnstileDialog.showModal();

      // Focus trap
      var closeBtn = turnstileDialog.querySelector("[data-modal-close]");
      if (closeBtn) closeBtn.focus();

      // Render Turnstile in dialog
      renderTurnstileInDialog();
    }

    function renderTurnstileInDialog() {
      var widgetEl = document.getElementById("turnstileEmailWidget");
      if (!widgetEl) return;

      if (!window.turnstile) {
        var attempts = 0;
        var retry = function() {
          attempts++;
          if (attempts < 100) {
            window.setTimeout(function() {
              if (window.turnstile) { doRender(); }
              else { retry(); }
            }, 200);
          } else {
            showAuthToast("人机验证失败，请重试", "error");
            closeTurnstileDialog();
          }
        };
        retry();
        return;
      }
      doRender();

      function doRender() {
        if (turnstileWidgetId !== null) {
          try { window.turnstile.reset(turnstileWidgetId); } catch(e) {}
        }
        try {
          turnstileWidgetId = window.turnstile.render(widgetEl, {
            sitekey: widgetEl.dataset.siteKey || "",
            theme: widgetEl.dataset.theme || "auto",
            size: widgetEl.dataset.size || "normal",
            action: "admin_email_code",
            callback: function(token) {
              turnstileResolved = true;
              closeTurnstileDialog();
              sendCodeRequest(token);
            },
            "error-callback": function() {
              showAuthToast("验证码发送失败，请稍后重试", "error");
              closeTurnstileDialog();
            },
            "expired-callback": function() {
              showAuthToast("注册服务暂时不可用", "error");
              turnstileResolved = false;
            },
            "timeout-callback": function() {
              showAuthToast("网络异常，请稍后重试", "error");
              closeTurnstileDialog();
            }
          });
        } catch(e) {
          showAuthToast("请完成人机验证", "error");
          closeTurnstileDialog();
        }
      }
    }

    function resetTurnstileWidget() {
      if (turnstileWidgetId !== null) {
        try { window.turnstile.reset(turnstileWidgetId); } catch(e) {}
        turnstileWidgetId = null;
      }
      turnstileResolved = false;
      var widgetEl = document.getElementById("turnstileEmailWidget");
      if (widgetEl) widgetEl.innerHTML = "";
    }

    function closeTurnstileDialog() {
      if (turnstileDialog instanceof HTMLDialogElement && turnstileDialog.open) {
        turnstileDialog.close();
      }
      if (!turnstileResolved) {
        resetTurnstileWidget();
      }
      // Return focus to trigger button
      triggerBtn.focus();
    }

    function sendCodeRequest(token) {
      if (triggerBtn.disabled && countdownTimer) return;

      triggerBtn.disabled = true;
      triggerBtn.setAttribute("aria-disabled", "true");
      triggerBtn.textContent = "发送中...";

      var formData = new FormData();
      formData.append("action", "send_email_code");
      formData.append("csrf_token", getCsrfToken());
      formData.append("email", pendingEmail || (emailField instanceof HTMLInputElement ? emailField.value : ""));
      formData.append("cf-turnstile-response", token);

      fetch("register.php", {
        method: "POST",
        body: formData,
        headers: { "Accept": "application/json" }
      })
      .then(function(resp) { return resp.json(); })
      .then(function(data) {
        if (data.success && typeof data.verification_token === "string" && data.verification_token.length >= 32) {
          if (tokenField instanceof HTMLInputElement) {
            tokenField.value = data.verification_token;
          }
          showAuthToast(data.message, "success");
          if (data.next_available_at) {
            triggerBtn.dataset.nextAvailableAt = data.next_available_at;
            var remaining = Math.max(0, data.next_available_at - Math.floor(Date.now() / 1000));
            if (remaining > 0) startCountdown(remaining);
          } else {
            startCountdown(60);
          }
        } else if (data.success) {
          showAuthToast("验证码发送状态异常，请重新获取", "error");
          if (codeInput instanceof HTMLInputElement) codeInput.value = "";
          if (tokenField instanceof HTMLInputElement) tokenField.value = "";
          triggerBtn.disabled = false;
          triggerBtn.setAttribute("aria-disabled", "false");
          triggerBtn.textContent = originalBtnText;
        } else {
          showAuthToast(data.message || "发送失败", "error");
          triggerBtn.disabled = false;
          triggerBtn.setAttribute("aria-disabled", "false");
          triggerBtn.textContent = originalBtnText;
          if (tokenField instanceof HTMLInputElement) tokenField.value = "";
        }
      })
      .catch(function() {
        showAuthToast("网络异常，请稍后重试", "error");
        triggerBtn.disabled = false;
        triggerBtn.setAttribute("aria-disabled", "false");
        triggerBtn.textContent = originalBtnText;
      });
    }

    triggerBtn.addEventListener("click", function(e) {
      e.preventDefault();
      openTurnstileDialog();
    });

    // Dialog close handlers
    if (turnstileDialog instanceof HTMLDialogElement) {
      turnstileDialog.addEventListener("close", function() {
        if (!turnstileResolved) {
          resetTurnstileWidget();
        }
      });

      turnstileDialog.addEventListener("keydown", function(e) {
        if (e.key === "Escape") {
          if (!turnstileResolved) resetTurnstileWidget();
        }
      });

      // Close button
      var closeButtons = turnstileDialog.querySelectorAll("[data-modal-close]");
      closeButtons.forEach(function(btn) {
        btn.addEventListener("click", function() {
          closeTurnstileDialog();
        });
      });

      // Click backdrop to close
      turnstileDialog.addEventListener("click", function(e) {
        if (e.target === turnstileDialog) {
          closeTurnstileDialog();
        }
      });
    }
  }


})();
