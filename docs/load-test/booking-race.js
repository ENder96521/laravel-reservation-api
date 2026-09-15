// k6 load test for the booking concurrency-control endpoint.
// See ../load-test-report.md for methodology and results.
//
// Usage:
//   php seed.php <capacity> <user_count>   # writes scenario.json
//   k6 run booking-race.js
//   BASE_URL=http://127.0.0.1:8098 k6 run booking-race.js

import http from 'k6/http';
import { SharedArray } from 'k6/data';
import { check } from 'k6';

const scenario = JSON.parse(open('./scenario.json'));
const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8098';
const TIME_SLOT_ID = scenario.time_slot_id;

const tokens = new SharedArray('tokens', function () {
  return scenario.tokens;
});

export const options = {
  scenarios: {
    booking_race: {
      executor: 'per-vu-iterations',
      vus: tokens.length,
      iterations: 1,
      maxDuration: '60s',
    },
  },
  thresholds: {
    checks: ['rate>0'],
  },
};

export default function () {
  const token = tokens[(__VU - 1) % tokens.length];

  const res = http.post(
    `${BASE_URL}/api/v1/bookings`,
    JSON.stringify({ time_slot_id: TIME_SLOT_ID }),
    {
      headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
    }
  );

  check(res, {
    'is 201 (booked) or 409 (full)': (r) => r.status === 201 || r.status === 409,
  });
}
