const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const scope = {};
vm.runInNewContext(readFileSync(require('node:path').join(__dirname, '../public/assets/video-wall-playback.js'), 'utf8'), scope);
const { Clock, normalizeRanges, unionRanges, wheelZoomFactor } = scope.SesameVideoWallPlayback;
test('timeline wheel sensitivity matches the DVR embed exponential curve', () => {
  for (const deltaY of [-120, -1, -0.25, 0, 0.25, 1, 120]) {
    assert.equal(wheelZoomFactor({deltaY, deltaMode: 0}), Math.exp(deltaY * 0.0015));
  }
  const tiny = wheelZoomFactor({deltaY: -1});
  assert(tiny > 0.998 && tiny < 1, 'a tiny wheel movement must not trigger a fixed 20% zoom');
  assert(Math.abs(tiny * wheelZoomFactor({deltaY: 1}) - 1) < 1e-12);
  assert(Math.abs(tiny ** 120 - wheelZoomFactor({deltaY: -120})) < 1e-12);
});
test('timeline wheel units are normalized for pixel, line and page devices', () => {
  assert.equal(wheelZoomFactor({deltaY: -3, deltaMode: 1}), wheelZoomFactor({deltaY: -48, deltaMode: 0}));
  assert.equal(wheelZoomFactor({deltaY: 1, deltaMode: 2}), wheelZoomFactor({deltaY: 240, deltaMode: 0}));
});
test('timeline wheel ignores horizontal-only and invalid deltas and bounds extreme input', () => {
  assert.equal(wheelZoomFactor({deltaX: 120, deltaY: 0}), 1);
  for (const deltaY of [NaN, Infinity, -Infinity, undefined]) assert.equal(wheelZoomFactor({deltaY}), 1);
  for (const deltaY of [-10000, 10000]) {
    assert.equal(wheelZoomFactor({deltaY}), Math.exp(Math.sign(deltaY) * 600 * 0.0015));
  }
});
test('shared UTC clock seeks, pauses, resumes and changes rate without discontinuity', () => {
  let now = 0; const clock = new Clock(() => now);
  clock.set(1000, 'archive', false, 1); now = 1000; assert.equal(clock.time(), 1001);
  clock.set(clock.time(), 'archive', true); now = 9000; assert.equal(clock.time(), 1001);
  clock.set(clock.time(), 'archive', false, 4); now = 10000; assert.equal(clock.time(), 1005);
  clock.set(700, 'archive', true); now = 20000; assert.equal(clock.time(), 700);
});
test('union is OR across cameras, clips, sorts and merges overlaps without filling gaps', () => {
  const a = [{from: 20, duration: 10}, {from: 10, duration: 5}];
  const b = [{from: 14, duration: 6}, {from: 35, duration: 1}];
  assert.equal(JSON.stringify(unionRanges([a, b, a, []], 12, 35.5)), JSON.stringify([{from: 12, duration: 18}, {from: 35, duration: 0.5}]));
  assert.equal(JSON.stringify(a), '[{"from":20,"duration":10},{"from":10,"duration":5}]');
  assert.equal(JSON.stringify(unionRanges([[{from: 1, duration: 1}], [{from: 2.1, duration: 1}]])), '[{"from":1,"duration":1},{"from":2.1,"duration":1}]');
  assert.equal(unionRanges([null, [], [{from: 1e308, duration: 1e308}]]).length, 0);
});
test('event union is independent of recording union', () => {
  const recording = unionRanges([[{from: 100, duration: 20}], [{from: 130, duration: 20}]]);
  const events = unionRanges([[{from: 105, duration: 2}], [{from: 106, duration: 5}, {from: 135, duration: 1}]]);
  assert.equal(JSON.stringify(recording), '[{"from":100,"duration":20},{"from":130,"duration":20}]');
  assert.equal(JSON.stringify(events), '[{"from":105,"duration":6},{"from":135,"duration":1}]');
});
test('untrusted range payloads reject invalid and unbounded entries', () => {
  assert.equal(normalizeRanges(null).length, 0);
  assert.equal(normalizeRanges([null, {from: -1, duration: 1}, {from: 1, duration: Infinity}, {from: 1, duration: 2}]).length, 1);
  assert.equal(normalizeRanges(Array(20001).fill({from: 1, duration: 1})).length, 20000);
});
