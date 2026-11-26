(function() {

//#region \0rolldown/runtime.js
	var __defProp = Object.defineProperty;
	var __name = (target, value) => __defProp(target, "name", {
		value,
		configurable: true
	});

//#endregion

//#region node_modules/@babel/runtime/helpers/esm/typeof.js
	function _typeof(o) {
		"@babel/helpers - typeof";
		return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function(o) {
			return typeof o;
		} : function(o) {
			return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o;
		}, _typeof(o);
	}

//#endregion
//#region node_modules/@babel/runtime/helpers/esm/toPrimitive.js
	function toPrimitive(t, r) {
		if ("object" != _typeof(t) || !t) return t;
		var e = t[Symbol.toPrimitive];
		if (void 0 !== e) {
			var i = e.call(t, r || "default");
			if ("object" != _typeof(i)) return i;
			throw new TypeError("@@toPrimitive must return a primitive value.");
		}
		return ("string" === r ? String : Number)(t);
	}

//#endregion
//#region node_modules/@babel/runtime/helpers/esm/toPropertyKey.js
	function toPropertyKey(t) {
		var i = toPrimitive(t, "string");
		return "symbol" == _typeof(i) ? i : i + "";
	}

//#endregion
//#region node_modules/@babel/runtime/helpers/esm/defineProperty.js
	function _defineProperty(e, r, t) {
		return (r = toPropertyKey(r)) in e ? Object.defineProperty(e, r, {
			value: t,
			enumerable: !0,
			configurable: !0,
			writable: !0
		}) : e[r] = t, e;
	}

//#endregion
//#region modules/agents/assets/js/admin/analytics/agent-ready-events.js
	var EVENT_DASHBOARD_CTA_CLICKED = "agent_readiness_dashboard_cta_clicked";
	var APP_TYPE_WP_ADMIN = "wpadmin";
	var LOCATION_L1 = "agent_readiness";
	var WINDOW_DASHBOARD = "wp_dashboard";
	var TRANSPORT_SEND_BEACON = "sendBeacon";
	var TARGET_TYPE_BUTTON = "button";

//#endregion
//#region modules/agents/assets/js/admin/analytics/track-agent-ready-event.js
	function ownKeys(e, r) {
		var t = Object.keys(e);
		if (Object.getOwnPropertySymbols) {
			var o = Object.getOwnPropertySymbols(e);
			r && (o = o.filter(function(r) {
				return Object.getOwnPropertyDescriptor(e, r).enumerable;
			})), t.push.apply(t, o);
		}
		return t;
	}
	function _objectSpread(e) {
		for (var r = 1; r < arguments.length; r++) {
			var t = null != arguments[r] ? arguments[r] : {};
			r % 2 ? ownKeys(Object(t), !0).forEach(function(r) {
				_defineProperty(e, r, t[r]);
			}) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys(Object(t)).forEach(function(r) {
				Object.defineProperty(e, r, Object.getOwnPropertyDescriptor(t, r));
			});
		}
		return e;
	}
	var dispatch = function dispatch(eventName, payload) {
		var _window$elementorComm;
		var _window$elementorComm2;
		for (var _len = arguments.length, dispatchOptions = new Array(_len > 2 ? _len - 2 : 0), _key = 2; _key < _len; _key++) dispatchOptions[_key - 2] = arguments[_key];
		return (_window$elementorComm = window.elementorCommon) === null || _window$elementorComm === void 0 || (_window$elementorComm = _window$elementorComm.eventsManager) === null || _window$elementorComm === void 0 || (_window$elementorComm2 = _window$elementorComm.dispatchEvent) === null || _window$elementorComm2 === void 0 ? void 0 : _window$elementorComm2.call.apply(_window$elementorComm2, [
			_window$elementorComm,
			eventName,
			_objectSpread({
				app_type: APP_TYPE_WP_ADMIN,
				location_l1: LOCATION_L1
			}, payload)
		].concat(dispatchOptions));
	};
	var trackDashboardCtaClicked = function trackDashboardCtaClicked(state) {
		return dispatch(EVENT_DASHBOARD_CTA_CLICKED, {
			window_name: WINDOW_DASHBOARD,
			interaction_type: "click",
			target_type: TARGET_TYPE_BUTTON,
			target_name: "dashboard_cta",
			interaction_result: state,
			target_location: "overview_widget",
			location_l2: "dashboard"
		}, { transport: TRANSPORT_SEND_BEACON });
	};

//#endregion
//#region modules/agents/assets/js/admin/dashboard-widget.js
	var CTA_SELECTOR = "[data-agent-ready-cta]";
	document.addEventListener("click", function(event) {
		var _event$target$closest;
		var _event$target;
		var cta = (_event$target$closest = (_event$target = event.target).closest) === null || _event$target$closest === void 0 ? void 0 : _event$target$closest.call(_event$target, CTA_SELECTOR);
		if (cta) trackDashboardCtaClicked(cta.dataset.agentReadyCta);
	});

//#endregion
})();
//# sourceMappingURL=agents-dashboard-widget.js.map