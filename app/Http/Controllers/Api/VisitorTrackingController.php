<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics\VisitorTrackingService;
use App\Models\VisitorProperty;
use App\Models\VisitorEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class VisitorTrackingController extends Controller
{
    public function __construct(private VisitorTrackingService $trackingService)
    {
    }

    /**
     * Ingestion endpoint for client-side SDK & beacons
     * POST /api/v1/analytics/collect
     */
    public function collect(Request $request)
    {
        $payload = $request->isJson() ? $request->json()->all() : $request->all();

        // Support sendBeacon stringified payload
        if (empty($payload) && $request->getContent()) {
            $decoded = json_decode($request->getContent(), true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $validated = validator($payload, [
            'property_id' => 'required|string|max:64',
            'property_uid' => 'sometimes|string|max:64',
            'anonymous_id' => 'sometimes|string|max:96',
            'visitor_id' => 'sometimes|string|max:96',
            'session_id' => 'sometimes|string|max:96',
            'type' => 'sometimes|string|in:pageview,custom,conversion,ecommerce,identify',
            'event' => 'sometimes|string|max:100',
            'category' => 'sometimes|string|max:64',
            'url' => 'sometimes|string|max:2048',
            'path' => 'sometimes|string|max:190',
            'title' => 'sometimes|string|max:255',
            'properties' => 'sometimes|array',
            'revenue' => 'sometimes|numeric|min:0|max:9999999999',
            'value' => 'sometimes|numeric|min:0|max:9999999999',
            'currency' => 'sometimes|string|size:3',
            'order_id' => 'sometimes|nullable|string|max:100',
        ])->validate();

        $result = $this->trackingService->ingest($validated, $request);

        return response()->json($result, $result['success'] ? 200 : 422)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With');
    }

    /**
     * Serve the Universal JavaScript Tracking Library
     * GET /tracker.js or GET /api/v1/analytics/tracker.js
     */
    public function serveTracker(Request $request): Response
    {
        $appUrl = rtrim(config('app.url') ?: 'https://nazbiz.io', '/');
        $collectUrl = $appUrl . '/api/v1/analytics/collect';

        $js = <<<JAVASCRIPT
/**
 * Nazeer Business Universal Visitor Analytics SDK v2.4
 * Privacy-first, lightweight, multi-tenant behavioral tracking
 */
(function(window, document) {
  'use strict';

  var NazAnalytics = window.NazAnalytics || function() {
    (NazAnalytics.q = NazAnalytics.q || []).push(arguments);
  };
  NazAnalytics.version = '2.4.0';

  var config = {
    propertyId: '',
    endpoint: '{$collectUrl}',
    anonymizeIp: true,
    respectDnt: true,
    cookieless: false
  };

  var sessionIdKey = '_naz_sid';
  var sessionActivityKey = '_naz_last_activity';
  var anonIdKey = '_naz_aid';
  var memorySessionId = null;
  var memorySessionActivityAt = 0;
  var memoryAnonymousId = null;

  function getStorage(key) {
    try { return localStorage.getItem(key); } catch(e) { return null; }
  }

  function setStorage(key, val) {
    try { localStorage.setItem(key, val); } catch(e) {}
  }

  function uuid() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
      var r = Math.random() * 16 | 0, v = c === 'x' ? r : (r & 0x3 | 0x8);
      return v.toString(16);
    });
  }

  function getAnonymousId() {
    var id = getStorage(anonIdKey);
    if (!id) {
      id = memoryAnonymousId || ('aid_' + uuid().replace(/-/g, '').substring(0, 16));
      memoryAnonymousId = id;
      setStorage(anonIdKey, id);
    }
    return id;
  }

  function getSessionId() {
    var sid = null;
    var lastActivityAt = 0;
    try { sid = sessionStorage.getItem(sessionIdKey); } catch(e) {}
    try { lastActivityAt = Number(sessionStorage.getItem(sessionActivityKey)) || 0; } catch(e) {}
    var nowMs = Date.now();
    if (!sid) sid = memorySessionId;
    if (!lastActivityAt) lastActivityAt = memorySessionActivityAt;
    if (!sid || !lastActivityAt || nowMs - lastActivityAt >= 30 * 60 * 1000) {
      sid = 'sid_' + uuid().replace(/-/g, '').substring(0, 16);
      memorySessionId = sid;
    }
    memorySessionId = sid;
    memorySessionActivityAt = nowMs;
    try {
      sessionStorage.setItem(sessionIdKey, sid);
      sessionStorage.setItem(sessionActivityKey, String(nowMs));
    } catch(e) {}
    return sid;
  }

  function getUtmParams() {
    var params = new URLSearchParams(window.location.search);
    return {
      utm_source: params.get('utm_source'),
      utm_medium: params.get('utm_medium'),
      utm_campaign: params.get('utm_campaign'),
      utm_term: params.get('utm_term'),
      utm_content: params.get('utm_content')
    };
  }

  function send(data) {
    if (config.respectDnt && (navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.globalPrivacyControl === true)) {
      return;
    }

    data.property_id = config.propertyId;
    data.anonymous_id = getAnonymousId();
    data.session_id = getSessionId();
    data.url = window.location.origin + window.location.pathname;
    data.path = window.location.pathname;
    data.title = document.title;
    if (document.referrer) {
      try {
        var referrerUrl = new URL(document.referrer);
        data.referrer = referrerUrl.origin + referrerUrl.pathname;
      } catch(e) { data.referrer = null; }
    } else {
      data.referrer = null;
    }
    data.screen_res = window.screen.width + 'x' + window.screen.height;
    data.language = navigator.language || navigator.userLanguage;

    var utms = getUtmParams();
    for (var k in utms) {
      if (utms[k]) data[k] = utms[k];
    }

    var payload = JSON.stringify(data);
    var sent = false;
    if (navigator.sendBeacon) {
      sent = navigator.sendBeacon(config.endpoint, payload);
    }
    if (!sent) {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', config.endpoint, true);
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.send(payload);
    }
  }

  // API Methods
  NazAnalytics.init = function(propId, opts) {
    config.propertyId = propId;
    if (opts) {
      for (var opt in opts) { config[opt] = opts[opt]; }
    }
  };

  NazAnalytics.page = function(title, path) {
    send({
      type: 'pageview',
      title: title || document.title,
      path: path || window.location.pathname
    });
  };

  NazAnalytics.track = function(eventName, properties) {
    send({
      type: 'custom',
      event: eventName,
      properties: properties || {}
    });
  };

  NazAnalytics.identify = function(userId, traits) {
    send({
      type: 'identify',
      event: 'user_identified',
      user_id: userId,
      traits: traits || {}
    });
  };

  NazAnalytics.conversion = function(goalName, value, currency, orderId) {
    var conversionData = {
      type: 'conversion',
      conversion_goal: goalName,
      revenue: value || 0,
      order_id: orderId || null
    };
    if (currency) conversionData.currency = String(currency).toUpperCase();
    send(conversionData);
  };

  // Process queued commands
  var queue = NazAnalytics.q || [];
  var methods = {
    init: NazAnalytics.init,
    page: NazAnalytics.page,
    track: NazAnalytics.track,
    identify: NazAnalytics.identify,
    conversion: NazAnalytics.conversion
  };
  NazAnalytics.q = [];
  for (var i = 0; i < queue.length; i++) {
    var args = Array.prototype.slice.call(queue[i]);
    var method = args.shift();
    if (typeof methods[method] === 'function') {
      methods[method].apply(NazAnalytics, args);
    }
  }

  NazAnalytics = function() {
    var args = Array.prototype.slice.call(arguments);
    var method = args.shift();
    if (typeof methods[method] === 'function') {
      return methods[method].apply(NazAnalytics, args);
    }
  };
  for (var methodName in methods) { NazAnalytics[methodName] = methods[methodName]; }
  NazAnalytics.version = '2.4.0';
  window.NazAnalytics = NazAnalytics;
})(window, document);
JAVASCRIPT;

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * Installation verification tester
     */
    public function verify(Request $request)
    {
        $validated = $request->validate(['property_id' => 'required|string|max:190']);
        $propertyUid = $validated['property_id'];
        $property = VisitorProperty::where('property_uid', $propertyUid)->where('is_active', true)->first();

        if ($property) {
            $latestEvent = VisitorEvent::where('property_id', $property->id)
                ->where('created_at', '>=', now()->subMinutes(10))
                ->latest('created_at')->first();
            if (!$latestEvent) {
                return response()->json([
                    'verified' => false,
                    'error' => 'No tracking event received in the last 10 minutes.',
                ], 422);
            }

            $property->update(['verified_at' => now()]);
            return response()->json([
                'verified' => true,
                'property_id' => $propertyUid,
                'verified_at' => $property->verified_at->toIso8601String(),
                'last_event_at' => $latestEvent->created_at?->toIso8601String(),
            ]);
        }

        return response()->json(['verified' => false, 'error' => 'Property not found'], 404);
    }
}
