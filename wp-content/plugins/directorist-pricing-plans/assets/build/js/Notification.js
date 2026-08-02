/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	var __webpack_modules__ = ({

/***/ "react"
/*!************************!*\
  !*** external "React" ***!
  \************************/
(module) {

module.exports = window["React"];

/***/ },

/***/ "@wordpress/dom-ready"
/*!**********************************!*\
  !*** external ["wp","domReady"] ***!
  \**********************************/
(module) {

module.exports = window["wp"]["domReady"];

/***/ },

/***/ "@wordpress/element"
/*!*********************************!*\
  !*** external ["wp","element"] ***!
  \*********************************/
(module) {

module.exports = window["wp"]["element"];

/***/ },

/***/ "@wordpress/hooks"
/*!*******************************!*\
  !*** external ["wp","hooks"] ***!
  \*******************************/
(module) {

module.exports = window["wp"]["hooks"];

/***/ },

/***/ "./node_modules/.pnpm/goober@2.1.18_csstype@3.2.3/node_modules/goober/dist/goober.modern.js"
/*!**************************************************************************************************!*\
  !*** ./node_modules/.pnpm/goober@2.1.18_csstype@3.2.3/node_modules/goober/dist/goober.modern.js ***!
  \**************************************************************************************************/
(__unused_webpack___webpack_module__, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   css: () => (/* binding */ u),
/* harmony export */   extractCss: () => (/* binding */ r),
/* harmony export */   glob: () => (/* binding */ b),
/* harmony export */   keyframes: () => (/* binding */ h),
/* harmony export */   setup: () => (/* binding */ m),
/* harmony export */   styled: () => (/* binding */ w)
/* harmony export */ });
let e={data:""},t=t=>{if("object"==typeof window){let e=(t?t.querySelector("#_goober"):window._goober)||Object.assign(document.createElement("style"),{innerHTML:" ",id:"_goober"});return e.nonce=window.__nonce__,e.parentNode||(t||document.head).appendChild(e),e.firstChild}return t||e},r=e=>{let r=t(e),l=r.data;return r.data="",l},l=/(?:([\u0080-\uFFFF\w-%@]+) *:? *([^{;]+?);|([^;}{]*?) *{)|(}\s*)/g,a=/\/\*[^]*?\*\/|  +/g,n=/\n+/g,o=(e,t)=>{let r="",l="",a="";for(let n in e){let c=e[n];"@"==n[0]?"i"==n[1]?r=n+" "+c+";":l+="f"==n[1]?o(c,n):n+"{"+o(c,"k"==n[1]?"":t)+"}":"object"==typeof c?l+=o(c,t?t.replace(/([^,])+/g,e=>n.replace(/([^,]*:\S+\([^)]*\))|([^,])+/g,t=>/&/.test(t)?t.replace(/&/g,e):e?e+" "+t:t)):n):null!=c&&(n=/^--/.test(n)?n:n.replace(/[A-Z]/g,"-$&").toLowerCase(),a+=o.p?o.p(n,c):n+":"+c+";")}return r+(t&&a?t+"{"+a+"}":a)+l},c={},s=e=>{if("object"==typeof e){let t="";for(let r in e)t+=r+s(e[r]);return t}return e},i=(e,t,r,i,p)=>{let u=s(e),d=c[u]||(c[u]=(e=>{let t=0,r=11;for(;t<e.length;)r=101*r+e.charCodeAt(t++)>>>0;return"go"+r})(u));if(!c[d]){let t=u!==e?e:(e=>{let t,r,o=[{}];for(;t=l.exec(e.replace(a,""));)t[4]?o.shift():t[3]?(r=t[3].replace(n," ").trim(),o.unshift(o[0][r]=o[0][r]||{})):o[0][t[1]]=t[2].replace(n," ").trim();return o[0]})(e);c[d]=o(p?{["@keyframes "+d]:t}:t,r?"":"."+d)}let f=r&&c.g?c.g:null;return r&&(c.g=c[d]),((e,t,r,l)=>{l?t.data=t.data.replace(l,e):-1===t.data.indexOf(e)&&(t.data=r?e+t.data:t.data+e)})(c[d],t,i,f),d},p=(e,t,r)=>e.reduce((e,l,a)=>{let n=t[a];if(n&&n.call){let e=n(r),t=e&&e.props&&e.props.className||/^go/.test(e)&&e;n=t?"."+t:e&&"object"==typeof e?e.props?"":o(e,""):!1===e?"":e}return e+l+(null==n?"":n)},"");function u(e){let r=this||{},l=e.call?e(r.p):e;return i(l.unshift?l.raw?p(l,[].slice.call(arguments,1),r.p):l.reduce((e,t)=>Object.assign(e,t&&t.call?t(r.p):t),{}):l,t(r.target),r.g,r.o,r.k)}let d,f,g,b=u.bind({g:1}),h=u.bind({k:1});function m(e,t,r,l){o.p=t,d=e,f=r,g=l}function w(e,t){let r=this||{};return function(){let l=arguments;function a(n,o){let c=Object.assign({},n),s=c.className||a.className;r.p=Object.assign({theme:f&&f()},c),r.o=/ *go\d+/.test(s),c.className=u.apply(r,l)+(s?" "+s:""),t&&(c.ref=o);let i=e;return e[0]&&(i=c.as||e,delete c.as),g&&i[0]&&g(c),d(i,c)}return t?t(a):a}}


/***/ },

/***/ "./node_modules/.pnpm/react-hot-toast@2.6.0_react-dom@18.3.1_react@18.3.1__react@18.3.1/node_modules/react-hot-toast/dist/index.mjs"
/*!******************************************************************************************************************************************!*\
  !*** ./node_modules/.pnpm/react-hot-toast@2.6.0_react-dom@18.3.1_react@18.3.1__react@18.3.1/node_modules/react-hot-toast/dist/index.mjs ***!
  \******************************************************************************************************************************************/
(__unused_webpack___webpack_module__, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   CheckmarkIcon: () => (/* binding */ L),
/* harmony export */   ErrorIcon: () => (/* binding */ C),
/* harmony export */   LoaderIcon: () => (/* binding */ F),
/* harmony export */   ToastBar: () => (/* binding */ N),
/* harmony export */   ToastIcon: () => (/* binding */ $),
/* harmony export */   Toaster: () => (/* binding */ Fe),
/* harmony export */   "default": () => (/* binding */ zt),
/* harmony export */   resolveValue: () => (/* binding */ h),
/* harmony export */   toast: () => (/* binding */ n),
/* harmony export */   useToaster: () => (/* binding */ w),
/* harmony export */   useToasterStore: () => (/* binding */ V)
/* harmony export */ });
/* harmony import */ var react__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react */ "react");
/* harmony import */ var goober__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! goober */ "./node_modules/.pnpm/goober@2.1.18_csstype@3.2.3/node_modules/goober/dist/goober.modern.js");
"use client";
var Z=e=>typeof e=="function",h=(e,t)=>Z(e)?e(t):e;var W=(()=>{let e=0;return()=>(++e).toString()})(),E=(()=>{let e;return()=>{if(e===void 0&&typeof window<"u"){let t=matchMedia("(prefers-reduced-motion: reduce)");e=!t||t.matches}return e}})();var re=20,k="default";var H=(e,t)=>{let{toastLimit:o}=e.settings;switch(t.type){case 0:return{...e,toasts:[t.toast,...e.toasts].slice(0,o)};case 1:return{...e,toasts:e.toasts.map(r=>r.id===t.toast.id?{...r,...t.toast}:r)};case 2:let{toast:s}=t;return H(e,{type:e.toasts.find(r=>r.id===s.id)?1:0,toast:s});case 3:let{toastId:a}=t;return{...e,toasts:e.toasts.map(r=>r.id===a||a===void 0?{...r,dismissed:!0,visible:!1}:r)};case 4:return t.toastId===void 0?{...e,toasts:[]}:{...e,toasts:e.toasts.filter(r=>r.id!==t.toastId)};case 5:return{...e,pausedAt:t.time};case 6:let i=t.time-(e.pausedAt||0);return{...e,pausedAt:void 0,toasts:e.toasts.map(r=>({...r,pauseDuration:r.pauseDuration+i}))}}},v=[],j={toasts:[],pausedAt:void 0,settings:{toastLimit:re}},f={},Y=(e,t=k)=>{f[t]=H(f[t]||j,e),v.forEach(([o,s])=>{o===t&&s(f[t])})},_=e=>Object.keys(f).forEach(t=>Y(e,t)),Q=e=>Object.keys(f).find(t=>f[t].toasts.some(o=>o.id===e)),S=(e=k)=>t=>{Y(t,e)},se={blank:4e3,error:4e3,success:2e3,loading:1/0,custom:4e3},V=(e={},t=k)=>{let[o,s]=(0,react__WEBPACK_IMPORTED_MODULE_0__.useState)(f[t]||j),a=(0,react__WEBPACK_IMPORTED_MODULE_0__.useRef)(f[t]);(0,react__WEBPACK_IMPORTED_MODULE_0__.useEffect)(()=>(a.current!==f[t]&&s(f[t]),v.push([t,s]),()=>{let r=v.findIndex(([l])=>l===t);r>-1&&v.splice(r,1)}),[t]);let i=o.toasts.map(r=>{var l,g,T;return{...e,...e[r.type],...r,removeDelay:r.removeDelay||((l=e[r.type])==null?void 0:l.removeDelay)||(e==null?void 0:e.removeDelay),duration:r.duration||((g=e[r.type])==null?void 0:g.duration)||(e==null?void 0:e.duration)||se[r.type],style:{...e.style,...(T=e[r.type])==null?void 0:T.style,...r.style}}});return{...o,toasts:i}};var ie=(e,t="blank",o)=>({createdAt:Date.now(),visible:!0,dismissed:!1,type:t,ariaProps:{role:"status","aria-live":"polite"},message:e,pauseDuration:0,...o,id:(o==null?void 0:o.id)||W()}),P=e=>(t,o)=>{let s=ie(t,e,o);return S(s.toasterId||Q(s.id))({type:2,toast:s}),s.id},n=(e,t)=>P("blank")(e,t);n.error=P("error");n.success=P("success");n.loading=P("loading");n.custom=P("custom");n.dismiss=(e,t)=>{let o={type:3,toastId:e};t?S(t)(o):_(o)};n.dismissAll=e=>n.dismiss(void 0,e);n.remove=(e,t)=>{let o={type:4,toastId:e};t?S(t)(o):_(o)};n.removeAll=e=>n.remove(void 0,e);n.promise=(e,t,o)=>{let s=n.loading(t.loading,{...o,...o==null?void 0:o.loading});return typeof e=="function"&&(e=e()),e.then(a=>{let i=t.success?h(t.success,a):void 0;return i?n.success(i,{id:s,...o,...o==null?void 0:o.success}):n.dismiss(s),a}).catch(a=>{let i=t.error?h(t.error,a):void 0;i?n.error(i,{id:s,...o,...o==null?void 0:o.error}):n.dismiss(s)}),e};var ce=1e3,w=(e,t="default")=>{let{toasts:o,pausedAt:s}=V(e,t),a=(0,react__WEBPACK_IMPORTED_MODULE_0__.useRef)(new Map).current,i=(0,react__WEBPACK_IMPORTED_MODULE_0__.useCallback)((c,m=ce)=>{if(a.has(c))return;let p=setTimeout(()=>{a.delete(c),r({type:4,toastId:c})},m);a.set(c,p)},[]);(0,react__WEBPACK_IMPORTED_MODULE_0__.useEffect)(()=>{if(s)return;let c=Date.now(),m=o.map(p=>{if(p.duration===1/0)return;let R=(p.duration||0)+p.pauseDuration-(c-p.createdAt);if(R<0){p.visible&&n.dismiss(p.id);return}return setTimeout(()=>n.dismiss(p.id,t),R)});return()=>{m.forEach(p=>p&&clearTimeout(p))}},[o,s,t]);let r=(0,react__WEBPACK_IMPORTED_MODULE_0__.useCallback)(S(t),[t]),l=(0,react__WEBPACK_IMPORTED_MODULE_0__.useCallback)(()=>{r({type:5,time:Date.now()})},[r]),g=(0,react__WEBPACK_IMPORTED_MODULE_0__.useCallback)((c,m)=>{r({type:1,toast:{id:c,height:m}})},[r]),T=(0,react__WEBPACK_IMPORTED_MODULE_0__.useCallback)(()=>{s&&r({type:6,time:Date.now()})},[s,r]),d=(0,react__WEBPACK_IMPORTED_MODULE_0__.useCallback)((c,m)=>{let{reverseOrder:p=!1,gutter:R=8,defaultPosition:z}=m||{},O=o.filter(u=>(u.position||z)===(c.position||z)&&u.height),K=O.findIndex(u=>u.id===c.id),B=O.filter((u,I)=>I<K&&u.visible).length;return O.filter(u=>u.visible).slice(...p?[B+1]:[0,B]).reduce((u,I)=>u+(I.height||0)+R,0)},[o]);return (0,react__WEBPACK_IMPORTED_MODULE_0__.useEffect)(()=>{o.forEach(c=>{if(c.dismissed)i(c.id,c.removeDelay);else{let m=a.get(c.id);m&&(clearTimeout(m),a.delete(c.id))}})},[o,i]),{toasts:o,handlers:{updateHeight:g,startPause:l,endPause:T,calculateOffset:d}}};var de=(0,goober__WEBPACK_IMPORTED_MODULE_1__.keyframes)`
from {
  transform: scale(0) rotate(45deg);
	opacity: 0;
}
to {
 transform: scale(1) rotate(45deg);
  opacity: 1;
}`,me=(0,goober__WEBPACK_IMPORTED_MODULE_1__.keyframes)`
from {
  transform: scale(0);
  opacity: 0;
}
to {
  transform: scale(1);
  opacity: 1;
}`,le=(0,goober__WEBPACK_IMPORTED_MODULE_1__.keyframes)`
from {
  transform: scale(0) rotate(90deg);
	opacity: 0;
}
to {
  transform: scale(1) rotate(90deg);
	opacity: 1;
}`,C=(0,goober__WEBPACK_IMPORTED_MODULE_1__.styled)("div")`
  width: 20px;
  opacity: 0;
  height: 20px;
  border-radius: 10px;
  background: ${e=>e.primary||"#ff4b4b"};
  position: relative;
  transform: rotate(45deg);

  animation: ${de} 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275)
    forwards;
  animation-delay: 100ms;

  &:after,
  &:before {
    content: '';
    animation: ${me} 0.15s ease-out forwards;
    animation-delay: 150ms;
    position: absolute;
    border-radius: 3px;
    opacity: 0;
    background: ${e=>e.secondary||"#fff"};
    bottom: 9px;
    left: 4px;
    height: 2px;
    width: 12px;
  }

  &:before {
    animation: ${le} 0.15s ease-out forwards;
    animation-delay: 180ms;
    transform: rotate(90deg);
  }
`;var Te=(0,goober__WEBPACK_IMPORTED_MODULE_1__.keyframes)`
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
`,F=(0,goober__WEBPACK_IMPORTED_MODULE_1__.styled)("div")`
  width: 12px;
  height: 12px;
  box-sizing: border-box;
  border: 2px solid;
  border-radius: 100%;
  border-color: ${e=>e.secondary||"#e0e0e0"};
  border-right-color: ${e=>e.primary||"#616161"};
  animation: ${Te} 1s linear infinite;
`;var ge=(0,goober__WEBPACK_IMPORTED_MODULE_1__.keyframes)`
from {
  transform: scale(0) rotate(45deg);
	opacity: 0;
}
to {
  transform: scale(1) rotate(45deg);
	opacity: 1;
}`,he=(0,goober__WEBPACK_IMPORTED_MODULE_1__.keyframes)`
0% {
	height: 0;
	width: 0;
	opacity: 0;
}
40% {
  height: 0;
	width: 6px;
	opacity: 1;
}
100% {
  opacity: 1;
  height: 10px;
}`,L=(0,goober__WEBPACK_IMPORTED_MODULE_1__.styled)("div")`
  width: 20px;
  opacity: 0;
  height: 20px;
  border-radius: 10px;
  background: ${e=>e.primary||"#61d345"};
  position: relative;
  transform: rotate(45deg);

  animation: ${ge} 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275)
    forwards;
  animation-delay: 100ms;
  &:after {
    content: '';
    box-sizing: border-box;
    animation: ${he} 0.2s ease-out forwards;
    opacity: 0;
    animation-delay: 200ms;
    position: absolute;
    border-right: 2px solid;
    border-bottom: 2px solid;
    border-color: ${e=>e.secondary||"#fff"};
    bottom: 6px;
    left: 6px;
    height: 10px;
    width: 6px;
  }
`;var be=(0,goober__WEBPACK_IMPORTED_MODULE_1__.styled)("div")`
  position: absolute;
`,Se=(0,goober__WEBPACK_IMPORTED_MODULE_1__.styled)("div")`
  position: relative;
  display: flex;
  justify-content: center;
  align-items: center;
  min-width: 20px;
  min-height: 20px;
`,Ae=(0,goober__WEBPACK_IMPORTED_MODULE_1__.keyframes)`
from {
  transform: scale(0.6);
  opacity: 0.4;
}
to {
  transform: scale(1);
  opacity: 1;
}`,Pe=(0,goober__WEBPACK_IMPORTED_MODULE_1__.styled)("div")`
  position: relative;
  transform: scale(0.6);
  opacity: 0.4;
  min-width: 20px;
  animation: ${Ae} 0.3s 0.12s cubic-bezier(0.175, 0.885, 0.32, 1.275)
    forwards;
`,$=({toast:e})=>{let{icon:t,type:o,iconTheme:s}=e;return t!==void 0?typeof t=="string"?react__WEBPACK_IMPORTED_MODULE_0__.createElement(Pe,null,t):t:o==="blank"?null:react__WEBPACK_IMPORTED_MODULE_0__.createElement(Se,null,react__WEBPACK_IMPORTED_MODULE_0__.createElement(F,{...s}),o!=="loading"&&react__WEBPACK_IMPORTED_MODULE_0__.createElement(be,null,o==="error"?react__WEBPACK_IMPORTED_MODULE_0__.createElement(C,{...s}):react__WEBPACK_IMPORTED_MODULE_0__.createElement(L,{...s})))};var Re=e=>`
0% {transform: translate3d(0,${e*-200}%,0) scale(.6); opacity:.5;}
100% {transform: translate3d(0,0,0) scale(1); opacity:1;}
`,Ee=e=>`
0% {transform: translate3d(0,0,-1px) scale(1); opacity:1;}
100% {transform: translate3d(0,${e*-150}%,-1px) scale(.6); opacity:0;}
`,ve="0%{opacity:0;} 100%{opacity:1;}",De="0%{opacity:1;} 100%{opacity:0;}",Oe=(0,goober__WEBPACK_IMPORTED_MODULE_1__.styled)("div")`
  display: flex;
  align-items: center;
  background: #fff;
  color: #363636;
  line-height: 1.3;
  will-change: transform;
  box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1), 0 3px 3px rgba(0, 0, 0, 0.05);
  max-width: 350px;
  pointer-events: auto;
  padding: 8px 10px;
  border-radius: 8px;
`,Ie=(0,goober__WEBPACK_IMPORTED_MODULE_1__.styled)("div")`
  display: flex;
  justify-content: center;
  margin: 4px 10px;
  color: inherit;
  flex: 1 1 auto;
  white-space: pre-line;
`,ke=(e,t)=>{let s=e.includes("top")?1:-1,[a,i]=E()?[ve,De]:[Re(s),Ee(s)];return{animation:t?`${(0,goober__WEBPACK_IMPORTED_MODULE_1__.keyframes)(a)} 0.35s cubic-bezier(.21,1.02,.73,1) forwards`:`${(0,goober__WEBPACK_IMPORTED_MODULE_1__.keyframes)(i)} 0.4s forwards cubic-bezier(.06,.71,.55,1)`}},N=react__WEBPACK_IMPORTED_MODULE_0__.memo(({toast:e,position:t,style:o,children:s})=>{let a=e.height?ke(e.position||t||"top-center",e.visible):{opacity:0},i=react__WEBPACK_IMPORTED_MODULE_0__.createElement($,{toast:e}),r=react__WEBPACK_IMPORTED_MODULE_0__.createElement(Ie,{...e.ariaProps},h(e.message,e));return react__WEBPACK_IMPORTED_MODULE_0__.createElement(Oe,{className:e.className,style:{...a,...o,...e.style}},typeof s=="function"?s({icon:i,message:r}):react__WEBPACK_IMPORTED_MODULE_0__.createElement(react__WEBPACK_IMPORTED_MODULE_0__.Fragment,null,i,r))});(0,goober__WEBPACK_IMPORTED_MODULE_1__.setup)(react__WEBPACK_IMPORTED_MODULE_0__.createElement);var we=({id:e,className:t,style:o,onHeightUpdate:s,children:a})=>{let i=react__WEBPACK_IMPORTED_MODULE_0__.useCallback(r=>{if(r){let l=()=>{let g=r.getBoundingClientRect().height;s(e,g)};l(),new MutationObserver(l).observe(r,{subtree:!0,childList:!0,characterData:!0})}},[e,s]);return react__WEBPACK_IMPORTED_MODULE_0__.createElement("div",{ref:i,className:t,style:o},a)},Me=(e,t)=>{let o=e.includes("top"),s=o?{top:0}:{bottom:0},a=e.includes("center")?{justifyContent:"center"}:e.includes("right")?{justifyContent:"flex-end"}:{};return{left:0,right:0,display:"flex",position:"absolute",transition:E()?void 0:"all 230ms cubic-bezier(.21,1.02,.73,1)",transform:`translateY(${t*(o?1:-1)}px)`,...s,...a}},Ce=(0,goober__WEBPACK_IMPORTED_MODULE_1__.css)`
  z-index: 9999;
  > * {
    pointer-events: auto;
  }
`,D=16,Fe=({reverseOrder:e,position:t="top-center",toastOptions:o,gutter:s,children:a,toasterId:i,containerStyle:r,containerClassName:l})=>{let{toasts:g,handlers:T}=w(o,i);return react__WEBPACK_IMPORTED_MODULE_0__.createElement("div",{"data-rht-toaster":i||"",style:{position:"fixed",zIndex:9999,top:D,left:D,right:D,bottom:D,pointerEvents:"none",...r},className:l,onMouseEnter:T.startPause,onMouseLeave:T.endPause},g.map(d=>{let c=d.position||t,m=T.calculateOffset(d,{reverseOrder:e,gutter:s,defaultPosition:t}),p=Me(c,m);return react__WEBPACK_IMPORTED_MODULE_0__.createElement(we,{id:d.id,key:d.id,onHeightUpdate:T.updateHeight,className:d.visible?Ce:"",style:p},d.type==="custom"?h(d.message,d):a?a(d):react__WEBPACK_IMPORTED_MODULE_0__.createElement(N,{toast:d,position:c}))}))};var zt=n;
//# sourceMappingURL=index.mjs.map

/***/ }

/******/ 	});
/************************************************************************/
/******/ 	// The module cache
/******/ 	var __webpack_module_cache__ = {};
/******/ 	
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/ 		// Check if module is in cache
/******/ 		var cachedModule = __webpack_module_cache__[moduleId];
/******/ 		if (cachedModule !== undefined) {
/******/ 			return cachedModule.exports;
/******/ 		}
/******/ 		// Check if module exists (development only)
/******/ 		if (__webpack_modules__[moduleId] === undefined) {
/******/ 			var e = new Error("Cannot find module '" + moduleId + "'");
/******/ 			e.code = 'MODULE_NOT_FOUND';
/******/ 			throw e;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = __webpack_module_cache__[moduleId] = {
/******/ 			// no module.id needed
/******/ 			// no module.loaded needed
/******/ 			exports: {}
/******/ 		};
/******/ 	
/******/ 		// Execute the module function
/******/ 		__webpack_modules__[moduleId](module, module.exports, __webpack_require__);
/******/ 	
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/compat get default export */
/******/ 	(() => {
/******/ 		// getDefaultExport function for compatibility with non-harmony modules
/******/ 		__webpack_require__.n = (module) => {
/******/ 			var getter = module && module.__esModule ?
/******/ 				() => (module['default']) :
/******/ 				() => (module);
/******/ 			__webpack_require__.d(getter, { a: getter });
/******/ 			return getter;
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/define property getters */
/******/ 	(() => {
/******/ 		// define getter functions for harmony exports
/******/ 		__webpack_require__.d = (exports, definition) => {
/******/ 			for(var key in definition) {
/******/ 				if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 					Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 				}
/******/ 			}
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/hasOwnProperty shorthand */
/******/ 	(() => {
/******/ 		__webpack_require__.o = (obj, prop) => (Object.prototype.hasOwnProperty.call(obj, prop))
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/make namespace object */
/******/ 	(() => {
/******/ 		// define __esModule on exports
/******/ 		__webpack_require__.r = (exports) => {
/******/ 			if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 				Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 			}
/******/ 			Object.defineProperty(exports, '__esModule', { value: true });
/******/ 		};
/******/ 	})();
/******/ 	
/************************************************************************/
var __webpack_exports__ = {};
// This entry needs to be wrapped in an IIFE because it needs to be isolated against other modules in the chunk.
(() => {
/*!*************************************************!*\
  !*** ./resources/js/components/Notification.ts ***!
  \*************************************************/
__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_dom_ready__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/dom-ready */ "@wordpress/dom-ready");
/* harmony import */ var _wordpress_dom_ready__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_dom_ready__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_hooks__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/hooks */ "@wordpress/hooks");
/* harmony import */ var _wordpress_hooks__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_hooks__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var react_hot_toast__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! react-hot-toast */ "./node_modules/.pnpm/react-hot-toast@2.6.0_react-dom@18.3.1_react@18.3.1__react@18.3.1/node_modules/react-hot-toast/dist/index.mjs");
/**
 * WordPress dependencies
 */





/**
 * External dependencies
 */


// Define a type for the notification data object for better type safety.

const Notification = () => {
  const pushNotification = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(data => {
    const message = data?.message ?? 'Directorist Pricing Plans Notification';
    const type = data?.type ?? 'success';
    if (type === 'success') {
      react_hot_toast__WEBPACK_IMPORTED_MODULE_3__["default"].success(message);
      return;
    }
    react_hot_toast__WEBPACK_IMPORTED_MODULE_3__["default"].error(message);
  }, []);

  // Register the notification handler when the component is mounted
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    (0,_wordpress_hooks__WEBPACK_IMPORTED_MODULE_2__.addAction)('directorist-pricing-plans-toast', 'component-directorist-pricing-plans-toast', pushNotification);
    return () => {
      // Clean up the action to prevent memory leaks
      (0,_wordpress_hooks__WEBPACK_IMPORTED_MODULE_2__.removeAction)('directorist-pricing-plans-toast', 'component-directorist-pricing-plans-toast');
    };
  }, [pushNotification]);

  // `@wordpress/element` and `react-hot-toast` can ship different React type
  // definitions in this project; casting avoids TS incompatibility only.
  return (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.createElement)(react_hot_toast__WEBPACK_IMPORTED_MODULE_3__.Toaster);
};
_wordpress_dom_ready__WEBPACK_IMPORTED_MODULE_1___default()(() => {
  const container = document.createElement('div');
  container.setAttribute('id', 'directorist-pricing-plans-toast');
  container.setAttribute('style', 'position: absolute; z-index: 9999999999; font-family: inherit; font-size: 14px;');
  document.body.appendChild(container);
  const root = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.createRoot)(container);
  root.render((0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.createElement)(Notification));
});
})();

/******/ })()
;
//# sourceMappingURL=Notification.js.map