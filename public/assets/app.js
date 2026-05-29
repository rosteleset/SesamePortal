(function () {
  const messages = window.SESAME_I18N || {};
  initMap();
  initCameraPositionEditor();
  initPlayer();
  initPreviewRefresh();
  initDensitySwitch();
  initGroupTreePickers();
  initAssignmentPickers();
  initLocalTimes();
  initPlayerBackBridge();

  function initMap() {
    if (!window.L || !window.SESAME_CAMERAS || !document.getElementById("map")) return;

    const cameras = window.SESAME_CAMERAS || [];
    const visibleCameras = cameras.filter((camera) => Number.isFinite(camera.lat) && Number.isFinite(camera.lng));
    const map = L.map("map", { zoomControl: true });
    setPlainLeafletAttribution(map);
    map.setView([25.2048, 55.2708], 10);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 19,
      attribution: "&copy; OpenStreetMap"
    }).addTo(map);

    const markerLayer = cameraMarkerLayer();
    visibleCameras.forEach((camera) => {
      const marker = L.marker([camera.lat, camera.lng], { icon: cameraIcon(camera.direction, camera.viewAngle, 42) });
      marker.bindPopup(cameraPopupHtml(camera), { className: "camera-popup", maxWidth: 280 });
      markerLayer.addLayer(marker);
    });
    markerLayer.addTo(map);

    fitMapToCameras(map, visibleCameras);
    map.on("popupopen", (event) => initPreviewRefresh(event.popup.getElement()));
  }

  function initCameraPositionEditor() {
    const container = document.getElementById("camera-position-map");
    if (!window.L || !container) return;

    const latInput = document.getElementById("camera-latitude") || document.querySelector('input[name="latitude"]');
    const lngInput = document.getElementById("camera-longitude") || document.querySelector('input[name="longitude"]');
    const directionInput = document.getElementById("camera-direction") || document.querySelector('input[name="direction_deg"]');
    if (!latInput || !lngInput) return;

    let committed = readEditorState();
    let pending = null;
    const start = hasPoint(committed) ? [committed.lat, committed.lng] : [25.2048, 55.2708];
    const editorMap = L.map(container, { zoomControl: true }).setView(start, hasPoint(committed) ? 16 : 4);
    setPlainLeafletAttribution(editorMap);
    const confirmBar = createMapConfirmBar(container);
    let cameraMarker = null;
    let directionMarker = null;
    let directionLine = null;

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 19,
      attribution: "&copy; OpenStreetMap"
    }).addTo(editorMap);

    const stageChange = (next, pan = false) => {
      pending = normalizeEditorState(next);
      renderEditorState(pending);
      showMapConfirm(confirmBar, true);
      if (pan && hasPoint(pending)) {
        editorMap.setView([pending.lat, pending.lng], Math.max(editorMap.getZoom(), 15));
      }
    };

    const syncFromInputs = () => {
      committed = readEditorState();
      pending = null;
      renderEditorState(committed);
      showMapConfirm(confirmBar, false);
      if (hasPoint(committed)) {
        editorMap.setView([committed.lat, committed.lng], Math.max(editorMap.getZoom(), 15));
      }
    };

    renderEditorState(committed);
    editorMap.on("click", (event) => {
      const base = pending || committed;
      stageChange({ ...base, lat: event.latlng.lat, lng: event.latlng.lng });
    });
    editorMap.on("zoomend", () => renderEditorState(pending || committed));
    latInput.addEventListener("change", syncFromInputs);
    lngInput.addEventListener("change", syncFromInputs);
    directionInput?.addEventListener("change", syncFromInputs);
    confirmBar.querySelector(".camera-map-apply")?.addEventListener("click", () => {
      if (!pending) return;
      committed = pending;
      pending = null;
      writeEditorState(committed);
      renderEditorState(committed);
      showMapConfirm(confirmBar, false);
    });
    confirmBar.querySelector(".camera-map-cancel")?.addEventListener("click", () => {
      pending = null;
      renderEditorState(committed);
      showMapConfirm(confirmBar, false);
    });
    document.querySelector(".camera-map-clear")?.addEventListener("click", () => {
      stageChange({ ...committed, lat: NaN, lng: NaN });
    });
    container.closest("form")?.addEventListener("submit", (event) => {
      if (!pending) return;
      event.preventDefault();
      showMapConfirm(confirmBar, true, true);
    });
    window.setTimeout(() => editorMap.invalidateSize(), 0);

    function readEditorState() {
      return normalizeEditorState({
        lat: toNumber(latInput.value),
        lng: toNumber(lngInput.value),
        direction: directionInput ? toNumber(directionInput.value) : 0
      });
    }

    function writeEditorState(state) {
      if (hasPoint(state)) {
        latInput.value = state.lat.toFixed(7);
        lngInput.value = state.lng.toFixed(7);
      } else {
        latInput.value = "";
        lngInput.value = "";
      }
      if (directionInput) {
        directionInput.value = String(state.direction);
      }
    }

    function renderEditorState(state) {
      if (!hasPoint(state)) {
        removeLayer(cameraMarker);
        removeLayer(directionMarker);
        removeLayer(directionLine);
        cameraMarker = null;
        directionMarker = null;
        directionLine = null;
        return;
      }

      const center = L.latLng(state.lat, state.lng);
      const target = directionTarget(center, state.direction, editorMap);
      if (!cameraMarker) {
        cameraMarker = L.marker(center, { draggable: true, icon: cameraIcon(state.direction, 60, 54) }).addTo(editorMap);
        cameraMarker.on("dragend", () => {
          const latlng = cameraMarker.getLatLng();
          const base = pending || committed;
          stageChange({ ...base, lat: latlng.lat, lng: latlng.lng });
        });
      } else {
        cameraMarker.setLatLng(center);
        cameraMarker.setIcon(cameraIcon(state.direction, 60, 54));
      }

      if (!directionMarker) {
        directionMarker = L.marker(target, { draggable: true, icon: directionHandleIcon(), zIndexOffset: 1000 }).addTo(editorMap);
        directionMarker.on("dragend", () => {
          const base = pending || committed;
          if (!hasPoint(base)) return;
          stageChange({ ...base, direction: bearingFromTarget(L.latLng(base.lat, base.lng), directionMarker.getLatLng(), editorMap) });
        });
      } else {
        directionMarker.setLatLng(target);
      }

      if (!directionLine) {
        directionLine = L.polyline([center, target], {
          color: "#C1964E",
          weight: 3,
          opacity: 0.9,
          dashArray: "6 6",
          className: "camera-direction-line"
        }).addTo(editorMap);
      } else {
        directionLine.setLatLngs([center, target]);
      }
    }
  }

  function initPlayer() {
    const page = document.querySelector(".player-page");
    if (!page) return;

    const stage = page.querySelector(".player-stage") || page;
    const frame = page.querySelector(".player-frame");
    const button = page.querySelector(".player-fullscreen");
    const backUrl = page.dataset.backUrl || "/";
    const fullscreenTarget = (stage.requestFullscreen || stage.webkitRequestFullscreen) ? stage : frame;
    const requestFullscreen = fullscreenTarget?.requestFullscreen || fullscreenTarget?.webkitRequestFullscreen;
    const exitFullscreen = document.exitFullscreen || document.webkitExitFullscreen;
    const fullscreenElement = () => document.fullscreenElement || document.webkitFullscreenElement;

    if (button && requestFullscreen) {
      button.addEventListener("click", async () => {
        try {
          if (fullscreenElement() && exitFullscreen) {
            await exitFullscreen.call(document);
          } else {
            await requestFullscreen.call(fullscreenTarget);
          }
        } catch (_error) {
          button.blur();
        }
      });

      const syncFullscreenState = () => {
        const active = fullscreenElement() === fullscreenTarget;
        const label = active ? tr("collapse", "Свернуть") : tr("fullscreen", "На весь экран");
        page.classList.toggle("is-fullscreen", active);
        button.textContent = label;
        button.setAttribute("aria-label", label);
        button.setAttribute("title", label);
      };
      document.addEventListener("fullscreenchange", syncFullscreenState);
      document.addEventListener("webkitfullscreenchange", syncFullscreenState);
    } else if (button) {
      button.hidden = true;
    }

    [page.querySelector(".player-edge-swipe"), page.querySelector(".player-toolbar")]
      .filter(Boolean)
      .forEach((zone) => bindBackSwipe(zone, backUrl));
  }

  function bindBackSwipe(element, backUrl) {
    let startX = 0;
    let startY = 0;
    let startAt = 0;

    element.addEventListener("touchstart", (event) => {
      const touch = event.changedTouches[0];
      startX = touch.clientX;
      startY = touch.clientY;
      startAt = Date.now();
    }, { passive: true });

    element.addEventListener("touchend", (event) => {
      const touch = event.changedTouches[0];
      const dx = touch.clientX - startX;
      const dy = touch.clientY - startY;
      const elapsed = Date.now() - startAt;
      if (elapsed < 700 && dx > 72 && Math.abs(dy) < 55) {
        window.location.assign(backUrl);
      }
    }, { passive: true });
  }

  function createMapConfirmBar(container) {
    const field = container.closest(".camera-position-field") || container.parentElement;
    const bar = document.createElement("div");
    bar.className = "camera-map-confirm";
    bar.hidden = true;
    bar.innerHTML = `
      <span>${escapeHtml(tr("mapChangePending", "Подтвердите изменение на карте"))}</span>
      <div>
        <button type="button" class="primary camera-map-apply">${escapeHtml(tr("apply", "Применить"))}</button>
        <button type="button" class="camera-map-cancel">${escapeHtml(tr("cancel", "Отменить"))}</button>
      </div>
    `;
    field.insertBefore(bar, container);
    return bar;
  }

  function showMapConfirm(bar, visible, attention = false) {
    bar.hidden = !visible;
    bar.classList.toggle("attention", Boolean(visible && attention));
    if (visible && attention) {
      bar.scrollIntoView({ block: "nearest" });
    }
  }

  function fitMapToCameras(map, cameras) {
    if (!cameras.length) return;

    if (cameras.length === 1) {
      map.setView([cameras[0].lat, cameras[0].lng], 16);
      return;
    }

    const bounds = L.latLngBounds(cameras.map((camera) => [camera.lat, camera.lng]));
    const padding = map.getSize().x < 520 ? [28, 28] : [52, 52];
    map.fitBounds(bounds, {
      paddingTopLeft: padding,
      paddingBottomRight: padding,
      maxZoom: 16
    });
  }

  function setPlainLeafletAttribution(map) {
    map.attributionControl?.setPrefix('<a href="https://leafletjs.com" title="A JavaScript library for interactive maps">Leaflet</a>');
  }

  function cameraMarkerLayer() {
    if (typeof L.markerClusterGroup !== "function") {
      return L.layerGroup();
    }

    return L.markerClusterGroup({
      showCoverageOnHover: false,
      removeOutsideVisibleBounds: true,
      spiderfyOnMaxZoom: true,
      disableClusteringAtZoom: 18,
      maxClusterRadius: 56,
      iconCreateFunction(cluster) {
        const count = cluster.getChildCount();
        return L.divIcon({
          className: "camera-cluster-icon",
          html: `<div class="camera-cluster" data-digits="${String(count).length}"><span>${escapeHtml(count)}</span></div>`,
          iconSize: [52, 52],
          iconAnchor: [26, 26]
        });
      }
    });
  }

  function normalizeEditorState(state) {
    return {
      lat: toNumber(state.lat),
      lng: toNumber(state.lng),
      direction: normalizeDirection(state.direction ?? state.dir ?? 0)
    };
  }

  function hasPoint(state) {
    return Number.isFinite(state.lat) && Number.isFinite(state.lng);
  }

  function directionTarget(center, direction, map) {
    const point = map.latLngToLayerPoint(center);
    const radians = normalizeDirection(direction) * Math.PI / 180;
    return map.layerPointToLatLng(L.point(
      point.x + Math.sin(radians) * 72,
      point.y - Math.cos(radians) * 72
    ));
  }

  function bearingFromTarget(center, target, map) {
    const a = map.latLngToLayerPoint(center);
    const b = map.latLngToLayerPoint(target);
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    if (Math.abs(dx) < 1 && Math.abs(dy) < 1) return 0;
    return normalizeDirection(Math.atan2(dx, -dy) * 180 / Math.PI);
  }

  function removeLayer(layer) {
    if (layer) {
      layer.remove();
    }
  }

  function normalizeDirection(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) return 0;
    return ((Math.round(number) % 360) + 360) % 360;
  }

  function initPlayerBackBridge() {
    const frames = Array.from(document.querySelectorAll(".player-frame"));
    if (!frames.length) return;

    window.addEventListener("message", (event) => {
      const data = event.data || {};
      if (!data || data.type !== "sesame-dvr:player-back") return;
      if (!frames.some((frame) => frame.contentWindow === event.source)) return;

      const targetUrl = safeSameOriginUrl(data.url);
      if (!targetUrl) return;

      event.source?.postMessage({ type: "sesame-dvr:player-back-ack", id: data.id || "" }, event.origin || "*");
      if (history.length > 1 && referrerMatches(targetUrl)) {
        history.back();
        return;
      }

      window.location.assign(relativeUrl(targetUrl));
    });
  }

  function safeSameOriginUrl(value) {
    try {
      const url = new URL(String(value || ""), window.location.href);
      return url.origin === window.location.origin ? url : null;
    } catch (_error) {
      return null;
    }
  }

  function referrerMatches(targetUrl) {
    if (!document.referrer) return false;
    const referrerUrl = safeSameOriginUrl(document.referrer);
    return Boolean(referrerUrl && relativeUrl(referrerUrl) === relativeUrl(targetUrl));
  }

  function relativeUrl(url) {
    return `${url.pathname}${url.search}${url.hash}`;
  }

  function cameraIcon(direction, viewAngle = 60, markerHitSize = 42) {
    const angle = normalizeDirection(direction);
    const fov = clamp(Number(viewAngle) || 60, 12, 170);
    const coneLength = 72;
    const coneWidth = Math.round(clamp(2 * Math.tan(fov * Math.PI / 360) * coneLength, 34, 132));
    const hitSize = Math.round(clamp(Number(markerHitSize) || 42, 30, 72));
    return L.divIcon({
      className: "camera-marker-icon",
      html: `<div class="camera-marker" style="--camera-direction:${angle}deg;--camera-cone-width:${coneWidth}px;--camera-cone-length:${coneLength}px"><span class="camera-view-cone"></span><span class="camera-marker-dot">●</span></div>`,
      iconSize: [hitSize, hitSize],
      iconAnchor: [hitSize / 2, hitSize / 2],
      popupAnchor: [0, -Math.round(hitSize / 2)]
    });
  }

  function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
  }

  function directionHandleIcon() {
    return L.divIcon({
      className: "",
      html: '<div class="camera-direction-handle"></div>',
      iconSize: [22, 22],
      iconAnchor: [11, 11]
    });
  }

  function cameraPopupHtml(camera) {
    const stateText = camera.streamUnavailable
      ? tr("streamUnavailable", "Поток недоступен")
      : tr("previewUnavailable", "Превью недоступно");
    const unavailableClass = camera.streamUnavailable ? " stream-unavailable" : "";
    const previewLabel = tr("openPlayer", tr("openVideo", "Открыть видео"));
    const preview = camera.preview
      ? `<a class="map-popup-preview is-loading${unavailableClass}" href="${escapeHtml(camera.player)}" aria-label="${escapeHtml(previewLabel)}"><img data-preview-src="${escapeHtml(camera.preview)}" data-preview-refresh="off" alt="" loading="lazy" decoding="async" hidden><span class="preview-spinner" aria-hidden="true"></span><span class="preview-state map-popup-preview-state">${escapeHtml(stateText)}</span><span class="preview-play" aria-hidden="true"></span></a>`
      : `<div class="map-popup-preview no-preview${unavailableClass}"><span class="preview-spinner" aria-hidden="true"></span><span class="preview-state map-popup-preview-state">${escapeHtml(stateText)}</span></div>`;
    const favoriteTitle = camera.favorite
      ? tr("removeFavorite", "Удалить из избранного")
      : tr("addFavorite", "Добавить в избранное");
    const favoriteClass = camera.favorite ? "favorite active" : "favorite";
    const favoriteIcon = camera.favorite ? "★" : "☆";

    return `
      <div class="map-popup-card">
        ${preview}
        <strong>${escapeHtml(camera.name)}</strong>
        <span>${escapeHtml(camera.server || "")}</span>
        <div class="map-popup-actions">
          <a href="${escapeHtml(camera.player)}">${escapeHtml(tr("openVideo", "Открыть видео"))}</a>
          <form method="post" action="/favorite/toggle" class="favorite-form">
            <input type="hidden" name="csrf" value="${escapeHtml(window.SESAME_CSRF || "")}">
            <input type="hidden" name="camera_id" value="${escapeHtml(camera.id)}">
            <button title="${escapeHtml(favoriteTitle)}" aria-label="${escapeHtml(favoriteTitle)}" class="${favoriteClass}">${favoriteIcon}</button>
          </form>
        </div>
      </div>
    `;
  }

  function initPreviewRefresh(root = document) {
    const images = Array.from(root.querySelectorAll("img[data-preview-src]"));
    if (!images.length) return;

    images.forEach((image, index) => {
      if (image.dataset.previewRefreshBound === "1") return;
      image.dataset.previewRefreshBound = "1";
      image.addEventListener("load", () => markPreviewReady(image));
      image.addEventListener("error", () => markPreviewMissing(image));
      if (!image.getAttribute("src")) {
        loadPreviewImage(image, image.dataset.previewSrc, { markMissingOnError: true });
      } else if (image.complete) {
        if (image.naturalWidth > 0) {
          markPreviewReady(image);
        } else {
          markPreviewMissing(image);
        }
      }
      if (image.dataset.previewRefresh === "off") return;
      const intervalMs = Math.max(10000, Number(image.dataset.previewRefreshMs) || 30000);
      window.setTimeout(function refreshLoop() {
        refreshPreview(image);
        window.setTimeout(refreshLoop, intervalMs);
      }, intervalMs + Math.min(index * 1200, intervalMs));
    });
  }

  function initAssignmentPickers() {
    document.querySelectorAll("[data-assignment-picker]").forEach((picker) => {
      const search = picker.querySelector(".assignment-search");
      const selectedOnly = picker.querySelector(".assignment-selected-only");
      const count = picker.querySelector(".assignment-count");
      const empty = picker.querySelector(".assignment-empty");
      const rows = Array.from(picker.querySelectorAll(".assignment-row"));
      const update = () => {
        const query = String(search?.value || "").trim().toLowerCase();
        const selectedOnlyMode = selectedOnly?.getAttribute("aria-pressed") === "true";
        let selected = 0;
        let visible = 0;
        rows.forEach((row) => {
          const checkbox = row.querySelector('input[type="checkbox"]');
          const checked = Boolean(checkbox?.checked);
          const matches = row.textContent.toLowerCase().includes(query);
          const show = matches && (!selectedOnlyMode || checked);
          if (checked) selected += 1;
          if (show) visible += 1;
          row.hidden = !show;
        });
        if (count) {
          count.textContent = `${tr("selectedCount", "Выбрано")}: ${selected} / ${rows.length}`;
        }
        if (empty) {
          empty.hidden = visible > 0;
        }
      };

      search?.addEventListener("input", update);
      selectedOnly?.addEventListener("click", () => {
        const active = selectedOnly.getAttribute("aria-pressed") === "true";
        selectedOnly.setAttribute("aria-pressed", active ? "false" : "true");
        selectedOnly.classList.toggle("active", !active);
        update();
      });
      rows.forEach((row) => row.querySelector('input[type="checkbox"]')?.addEventListener("change", update));
      update();
    });
  }

  function initDensitySwitch() {
    const switcher = document.querySelector(".density-switch");
    const grid = document.querySelector(".camera-grid");
    if (!switcher || !grid) return;

    const setColumns = (cols) => {
      const normalized = String(clamp(Math.round(Number(cols) || 3), 2, 6));
      grid.classList.remove("cols-2", "cols-3", "cols-4", "cols-5", "cols-6");
      grid.classList.add(`cols-${normalized}`);

      switcher.querySelectorAll("[data-cols]").forEach((link) => {
        const active = link.dataset.cols === normalized;
        link.classList.toggle("active", active);
        link.setAttribute("aria-current", active ? "true" : "false");
      });

      document.querySelectorAll('input[name="cols"]').forEach((input) => {
        input.value = normalized;
      });
      updateViewerLinks(normalized);
    };

    const current = switcher.querySelector("[data-cols].active")?.dataset.cols;
    if (current) {
      setColumns(current);
    }
  }

  function updateViewerLinks(cols) {
    document.querySelectorAll(".viewer-filters a[href^='/'], .pager a[href^='/']").forEach((link) => {
      if (link.closest(".density-switch")) return;
      const href = link.getAttribute("href");
      if (!href) return;
      const url = new URL(href, window.location.origin);
      if (url.pathname !== "/") return;
      url.searchParams.set("cols", cols);
      link.setAttribute("href", url.pathname + url.search + url.hash);
    });
  }

  function initGroupTreePickers(root = document) {
    const pickers = Array.from(root.querySelectorAll("[data-group-tree-picker]"));
    const toggles = Array.from(root.querySelectorAll("[data-group-tree-toggle]"));
    if (!pickers.length && !toggles.length) return;

    const closePicker = (picker) => {
      const trigger = picker.querySelector(".group-tree-trigger");
      const menu = picker.querySelector("[data-group-tree-menu]");
      if (!trigger || !menu) return;
      menu.hidden = true;
      trigger.setAttribute("aria-expanded", "false");
    };

    const openPicker = (picker) => {
      pickers.forEach((other) => {
        if (other !== picker) closePicker(other);
      });
      const trigger = picker.querySelector(".group-tree-trigger");
      const menu = picker.querySelector("[data-group-tree-menu]");
      if (!trigger || !menu) return;
      menu.hidden = false;
      trigger.setAttribute("aria-expanded", "true");
    };

    const toggleNode = (toggle) => {
      const node = toggle.closest("[data-group-tree-node]");
      const children = Array.from(node?.children || []).find((child) => child.matches("[data-group-tree-children]"));
      if (!children) return;
      const expanded = toggle.getAttribute("aria-expanded") === "true";
      const nextExpanded = !expanded;
      children.hidden = !nextExpanded;
      toggle.setAttribute("aria-expanded", nextExpanded ? "true" : "false");
      toggle.textContent = nextExpanded ? "-" : "+";
      toggle.setAttribute("aria-label", nextExpanded ? (toggle.dataset.collapseLabel || "") : (toggle.dataset.expandLabel || ""));
      node?.classList.toggle("is-expanded", nextExpanded);
    };

    toggles.forEach((toggle) => {
      toggle.addEventListener("click", (event) => {
        event.preventDefault();
        toggleNode(toggle);
      });
    });

    pickers.forEach((picker) => {
      const trigger = picker.querySelector(".group-tree-trigger");
      const menu = picker.querySelector("[data-group-tree-menu]");
      if (!trigger || !menu) return;

      trigger.addEventListener("click", (event) => {
        event.preventDefault();
        if (menu.hidden) {
          openPicker(picker);
        } else {
          closePicker(picker);
        }
      });

      picker.addEventListener("click", (event) => {
        const option = event.target.closest("[data-group-tree-select-value]");
        if (!option || !picker.contains(option)) return;
        event.preventDefault();
        const input = picker.querySelector('input[type="hidden"]');
        const label = picker.querySelector("[data-group-tree-trigger-label]");
        if (input) input.value = option.dataset.groupTreeSelectValue || "";
        if (label) label.textContent = option.dataset.groupTreeSelectLabel || option.textContent.trim();
        picker.querySelectorAll("[data-group-tree-select-value]").forEach((candidate) => {
          const active = candidate === option;
          candidate.classList.toggle("active", active);
          if (active) {
            candidate.setAttribute("aria-current", "true");
          } else {
            candidate.removeAttribute("aria-current");
          }
        });
        closePicker(picker);
      });
    });

    document.addEventListener("click", (event) => {
      pickers.forEach((picker) => {
        if (!picker.contains(event.target)) {
          closePicker(picker);
        }
      });
    });

    document.addEventListener("keydown", (event) => {
      if (event.key !== "Escape") return;
      pickers.forEach(closePicker);
    });
  }

  function initLocalTimes(root = document) {
    const times = Array.from(root.querySelectorAll("time.local-time[datetime]"));
    if (!times.length) return;

    const locale = document.documentElement.lang || undefined;
    const formatter = new Intl.DateTimeFormat(locale, {
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit"
    });
    const timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone || "";

    times.forEach((time) => {
      if (time.dataset.localTimeBound === "1") return;
      const date = new Date(time.dateTime || time.getAttribute("datetime") || "");
      if (Number.isNaN(date.getTime())) return;
      time.dataset.localTimeBound = "1";
      time.textContent = formatter.format(date);
      if (timeZone) {
        time.title = `${time.getAttribute("datetime")} -> ${timeZone}`;
      }
    });
  }

  function refreshPreview(image) {
    const source = image.dataset.previewSrc;
    if (!source) return;
    if (image.dataset.previewLoading === "1") return;

    const separator = source.includes("?") ? "&" : "?";
    const nextSrc = `${source}${separator}_=${Date.now()}`;
    loadPreviewImage(image, nextSrc, { markMissingOnError: false });
  }

  function loadPreviewImage(image, nextSrc, options = {}) {
    if (!nextSrc) return;
    const preloader = new Image();
    preloader.decoding = "async";
    image.dataset.previewLoading = "1";
    const container = image.closest(".preview, .map-popup-preview");
    const showLoader = options.showLoader ?? (image.hidden || !image.getAttribute("src"));
    if (showLoader) {
      container?.classList.add("is-loading");
    }

    const finish = () => {
      delete image.dataset.previewLoading;
      container?.classList.remove("is-loading");
    };
    preloader.onload = async () => {
      try {
        await preloader.decode?.();
      } catch {
        // The image is already loaded; decode is only used to avoid visible swaps.
      }
      if (!image.isConnected) {
        finish();
        return;
      }
      image.src = nextSrc;
      markPreviewReady(image);
      finish();
    };
    preloader.onerror = () => {
      if (options.markMissingOnError && image.isConnected) {
        markPreviewMissing(image);
      }
      finish();
    };
    preloader.src = nextSrc;
  }

  function markPreviewReady(image) {
    image.hidden = false;
    const container = image.closest(".preview, .map-popup-preview");
    container?.classList.remove("no-preview", "is-loading");
    container?.classList.add("has-preview");
  }

  function markPreviewMissing(image) {
    image.hidden = true;
    const container = image.closest(".preview, .map-popup-preview");
    container?.classList.remove("is-loading");
    container?.classList.remove("has-preview");
    container?.classList.add("no-preview");
  }

  function toNumber(value) {
    if (value === null || value === undefined || value === "") return NaN;
    return Number(String(value).replace(",", "."));
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;");
  }

  function tr(key, fallback) {
    return messages[key] || fallback;
  }
})();
