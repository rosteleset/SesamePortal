const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const scope = {};
vm.runInNewContext(readFileSync(require('node:path').join(__dirname, '../public/assets/video-wall-playback.js'), 'utf8'), scope);
const { Clock, normalizeRanges } = scope.SesameVideoWallPlayback;
test('shared UTC clock seeks, pauses, resumes and changes rate without discontinuity', () => {
  let now = 0; const clock = new Clock(() => now);
  clock.set(1000, 'archive', false, 1); now = 1000; assert.equal(clock.time(), 1001);
  clock.set(clock.time(), 'archive', true); now = 9000; assert.equal(clock.time(), 1001);
  clock.set(clock.time(), 'archive', false, 4); now = 10000; assert.equal(clock.time(), 1005);
  clock.set(700, 'archive', true); now = 20000; assert.equal(clock.time(), 700);
});
test('untrusted range payloads reject invalid and unbounded entries', () => {
  assert.equal(normalizeRanges(null).length, 0);
  assert.equal(normalizeRanges([null, {from: -1, duration: 1}, {from: 1, duration: Infinity}, {from: 1, duration: 2}]).length, 1);
  assert.equal(normalizeRanges(Array(20001).fill({from: 1, duration: 1})).length, 20000);
});
