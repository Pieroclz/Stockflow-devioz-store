const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const source = fs.readFileSync(path.join(__dirname, '..', 'assets/js/app.js'), 'utf8');
const start = source.indexOf("adminProductSearchInputs.forEach");
const end = source.indexOf("const svgNode", start);
const handler = source.slice(start, end);

test('short autocomplete input cancels queued and stale requests', () => {
    assert.ok(start >= 0 && end > start, 'autocomplete handler was not found');
    const clearTimer = handler.indexOf('window.clearTimeout(timer)');
    const shortQueryGuard = handler.indexOf('if (query.length < 2)');
    assert.ok(clearTimer >= 0 && clearTimer < shortQueryGuard, 'short input must clear the debounce before returning');
    assert.match(handler, /state\?\.abort\) state\.abort\.abort\(\)/, 'new input must abort in-flight work');
    assert.match(handler, /generation !== searchGeneration/, 'stale responses must be invalidated before rendering');
});

console.log('admin product autocomplete cancellation: OK');
