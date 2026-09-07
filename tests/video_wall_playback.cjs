const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const scope = {};
vm.runInNewContext(readFileSync(require('node:path').join(__dirname, '../public/assets/video-wall-playback.js'), 'utf8'), scope);
const { Clock, DriftGuard, normalizeRanges, unionRanges, wheelZoomFactor, shouldResumeAtRecording } = scope.SesameVideoWallPlayback;
function gapFixture() {
  return {
    archive: true, rangesLoaded: true, seekUnix: 197,
    state: {error: 'noRecording', busy: false},
    ranges: [{from: 100, duration: 90}, {from: 200, duration: 10}, {from: 215, duration: 5}],
  };
}
const archiveClock = unix => ({mode: 'archive', paused: false, time: () => unix});
test('gap recovery starts on the next per-camera range, including fractional boundaries', () => {
  const item = gapFixture();
  for (const unix of [197, 199.999, 210, 214.999, 220]) assert.equal(shouldResumeAtRecording(item, archiveClock(unix)), false);
  for (const unix of [200, 200.001, 209.999, 215]) assert.equal(shouldResumeAtRecording(item, archiveClock(unix)), true);
  item.ranges = [{from: 200.25, duration: 0.5}];
  assert.equal(shouldResumeAtRecording(item, archiveClock(200.249)), false);
  assert.equal(shouldResumeAtRecording(item, archiveClock(200.25)), true);
  assert.equal(shouldResumeAtRecording(item, archiveClock(200.75)), false);
});
test('one immediate attempt per entered range; repeated failures retain the normal retry limit', () => {
  const item = gapFixture();
  assert.equal(shouldResumeAtRecording(item, archiveClock(200.4)), true);
  item.seekUnix = 200.4;
  for (const unix of [200.4, 201, 209.999]) assert.equal(shouldResumeAtRecording(item, archiveClock(unix)), false);
  assert.equal(shouldResumeAtRecording(item, archiveClock(215)), true);
  item.seekUnix = 200;
  assert.equal(shouldResumeAtRecording(item, archiveClock(201)), false, 'a failed seek exactly at a range start is not retried on every tick');
});
test('no gap shortcut for paused/live clocks, pending seeks, other errors or unavailable ranges', () => {
  const clock = archiveClock(201);
  for (const patch of [{archive: false}, {rangesLoaded: false}, {ranges: []}, {seekUnix: null}, {seekUnix: NaN}, {state: null}, {state: {error: 'noRecording', busy: true}}, {state: {error: 'archiveDenied'}}, {state: {error: 'buffering'}}, {state: {error: null}}]) {
    assert.equal(shouldResumeAtRecording({...gapFixture(), ...patch}, clock), false);
  }
  assert.equal(shouldResumeAtRecording(gapFixture(), {...clock, mode: 'live'}), false);
  assert.equal(shouldResumeAtRecording(gapFixture(), {...clock, paused: true}), false);
  assert.equal(shouldResumeAtRecording({...gapFixture(), ranges: [{from: 100, duration: 500}]}, clock), false,
    'an existing coarse range or another camera recording does not justify repeated early retries');
});
test('gap recovery follows the shared media time at every playback rate', () => {
  for (const rate of [0.5, 1, 2, 4, 8]) {
    let now = 0;
    const clock = new Clock(() => now);
    clock.set(197, 'archive', false, rate);
    now = 2999 / rate; assert.equal(shouldResumeAtRecording(gapFixture(), clock), false);
    now = 3000 / rate; assert.equal(shouldResumeAtRecording(gapFixture(), clock), true);
  }
});
function driftFixture(rate = 1, paused = false) {
  let now = 0;
  const clock = new Clock(() => now), guard = new DriftGuard();
  clock.set(1000, 'archive', paused, rate);
  const sample = (offset, patch = {}) => {
    const state = {mode: 'archive', ready: true, busy: false, ended: false, paused, rate, unix: clock.time() + offset, ...patch};
    guard.update(state, clock, now, now); return state;
  };
  return {clock, guard, sample, advance: ms => { now += ms; }, now: () => now};
}
test('running cameras tolerate both lead and lag up to 10 seconds at every playback speed', () => {
  for (const rate of [0.5, 1, 2, 4, 8]) for (const offset of [-10, -4, 0, 4, 10]) {
    const f = driftFixture(rate);
    for (let n = 0; n < 40; n++) {
      f.sample(offset); assert.equal(f.guard.required, false); f.advance(500);
    }
  }
});
test('moderate drift needs three seconds of persistent deviation in the same direction', () => {
  for (const sign of [-1, 1]) {
    const f = driftFixture();
    f.sample(sign * 11); f.advance(2999); f.sample(sign * 12);
    assert.equal(f.guard.required, false);
    f.advance(1); f.sample(sign * 11);
    assert.equal(f.guard.required, true);
    f.sample(sign * 4); assert.equal(f.guard.required, false);
    f.sample(sign * 12); f.advance(2500); f.sample(sign * -12);
    assert.equal(f.guard.required, false, 'changing direction starts a new observation period');
    f.advance(500); f.sample(sign * -12); assert.equal(f.guard.required, false);
    f.advance(2500); f.sample(sign * -12); assert.equal(f.guard.required, true);
  }
});
test('large drift skips the grace period and pause retains the previous stricter tolerance', () => {
  for (const offset of [-30.01, 30.01]) {
    const f = driftFixture(); f.sample(offset); assert.equal(f.guard.required, true);
  }
  for (const offset of [-30, 30]) {
    const f = driftFixture(); f.sample(offset); assert.equal(f.guard.required, false);
  }
  const paused = driftFixture(1, true);
  paused.sample(3); assert.equal(paused.guard.required, false);
  paused.sample(4); assert.equal(paused.guard.required, true);
  paused.guard.reset(); assert.equal(paused.guard.required, false);
  assert.equal(paused.guard.since, null);
});
test('drift comparison accounts for state age and actual player speed, but not a paused player', () => {
  const f = driftFixture(8), state = f.sample(-8);
  f.advance(500); f.guard.update(state, f.clock, f.now(), 0);
  assert.equal(f.guard.since, null, 'an aligned older sample is not additional drift at 8x');
  f.guard.update({...state, paused: true}, f.clock, f.now(), 0);
  assert.equal(f.guard.since, 500, 'a paused player must not be extrapolated');
  f.guard.reset(); f.guard.update({...state, rate: 1}, f.clock, f.now(), 0);
  assert.equal(f.guard.since, 500, 'use the player rate, not the requested wall rate');
});
test('buffering, errors, ended playback, invalid/stale reports and live mode reset drift history', () => {
  for (const patch of [{ready: false}, {busy: true}, {error: 'noRecording'}, {error: 'archiveDenied'}, {ended: true}, {unix: null}, {unix: Infinity}, {mode: 'live'}]) {
    const f = driftFixture(); f.sample(12); f.advance(2000); f.sample(12, patch);
    assert.equal(f.guard.since, null); assert.equal(f.guard.required, false);
    f.sample(12); f.advance(1000); f.sample(12); assert.equal(f.guard.required, false);
  }
  const f = driftFixture(), state = f.sample(12);
  f.advance(3001); f.guard.update(state, f.clock, f.now(), 0);
  assert.equal(f.guard.since, null);
  f.sample(12); f.guard.update(null, f.clock, f.now(), f.now()); assert.equal(f.guard.since, null);
  f.sample(12); f.clock.set(1000, 'live'); f.guard.update(state, f.clock, f.now(), f.now());
  assert.equal(f.guard.required, false);
});
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
