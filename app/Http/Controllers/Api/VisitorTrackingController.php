<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics\VisitorTrackingService;
use App\Models\VisitorProperty;
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

        $result = $this->trackingService->ingest($payload, $request);

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
  var anonIdKey = '_naz_aid';

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
      id = 'aid_' + uuid().replace(/-/g, '').substring(0, 16);
      setStorage(anonIdKey, id);
    }
    return id;
  }

  function getSessionId() {
    var sid = sessionStorage.getItem(sessionIdKey);
    if (!sid) {
      sid = 'sid_' + uuid().replace(/-/g, '').substring(0, 16);
      try { sessionStorage.setItem(sessionIdKey, sid); } catch(e) {}
    }
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
    if (config.respectDnt && (navigator.doNotTrack === '1' || window.doNotTrack === '1')) {
      return;
    }

    data.property_id = config.propertyId;
    data.anonymous_id = getAnonymousId();
    data.session_id = getSessionId();
    data.url = window.location.href;
    data.path = window.location.pathname;
    data.title = document.title;
    data.referrer = document.referrer || null;
    data.screen_res = window.screen.width + 'x' + window.screen.height;
    data.language = navigator.language || navigator.userLanguage;

    var utms = getUtmParams();
    for (var k in utms) {
      if (utms[k]) data[k] = utms[k];
    }

    var payload = JSON.stringify(data);
    if (navigator.sendBeacon) {
      navigator.sendBeacon(config.endpoint, payload);
    } else {
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
    send({
      type: 'conversion',
      conversion_goal: goalName,
      revenue: value || 0,
      currency: currency || 'SAR',
      order_id: orderId || null
    });
  };

  // Process queued commands
  var queue = NazAnalytics.q || [];
  NazAnalytics.q = [];
  for (var i = 0; i < queue.length; i++) {
    var args = Array.prototype.slice.call(queue[i]);
    var method = args.shift();
    if (typeof NazAnalytics[method] === 'function') {
      NazAnalytics[method].apply(NazAnalytics, args);
    }
  }

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
        $propertyUid = $request->input('property_id');
        $property = VisitorProperty::where('property_uid', $propertyUid)->first();

        if ($property) {
            $property->update(['verified_at' => now()]);
            return response()->json([
                'verified' => true,
                'property_id' => $propertyUid,
                'verified_at' => $property->verified_at->toIso8601String(),
            ]);
        }

        return response()->json(['verified' => false, 'error' => 'Property not found'], 404);
    }
}
