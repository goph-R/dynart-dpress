/**
 * `EnlighterJS.tokenize()`, which `assets/enlighter/build.js` adds to the bundle, with no toolchain
 *
 *   node assets/enlighter-tokenize.test.js
 *
 * The admin's code editor colours every language it has no grammar of its own for with this - so
 * what is asserted is what the backdrop needs: spans in order that never overlap, the plain text
 * left out, a language found by any of its names, and a painting that is the text character for
 * character.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('assert');

global.window = global;
global.document = {};
// indirect, so the bundle's `var EnlighterJS` lands in the global scope
(0, eval)(fs.readFileSync(path.join(__dirname, 'enlighter', 'enlighterjs.dpress.min.js'), 'utf8'));
eval(fs.readFileSync(path.join(__dirname, 'code-backdrop.js'), 'utf8'));

const tokenize = EnlighterJS.tokenize;

function typed(code, language) {
    return tokenize(code, language).map(span => span.type + ':' + code.slice(span.start, span.end));
}

const tests = {

    'a language\'s tokens, with the plain text left out'() {
        assert.deepStrictEqual(typed('local x = "hi" -- note', 'lua'), ['k2:local', 's0:"hi"', 'c0:-- note']);
    },

    'the language dpress adds is in it too'() {
        assert.ok(typed('begin writeln(\'x\') end', 'pascal').includes('s0:\'x\''));
    },

    'a language is found by an alias, in any case'() {
        assert.notStrictEqual(tokenize('<a/>', 'html'), null, 'html is the xml language\'s alias');
        assert.notStrictEqual(tokenize('x = 1', 'JS'), null);
        assert.notStrictEqual(tokenize('begin end', 'PASCAL'), null);
    },

    'a language it does not have is null, not an empty colouring'() {
        assert.strictEqual(tokenize('x', 'klingon'), null);
        assert.strictEqual(tokenize('x', ''), null);
    },

    'the spans are in order and never overlap, over a real file'() {
        const code = fs.readFileSync(path.join(__dirname, 'enlighter', 'build.js'), 'utf8');
        let at = 0;
        for (const span of tokenize(code, 'javascript')) {
            assert.ok(span.start >= at && span.end > span.start, 'a span at ' + span.start);
            at = span.end;
        }
    },

    'the backdrop paints it character for character'() {
        const code = 'function f(a)\n  return a .. "<b>"\nend\n';
        const grammar = {prefix: 'enl-', tokenize: text => tokenize(text, 'lua')};
        const painted = window.Dpress.backdrop.render(code, grammar);
        const back = painted.replace(/<\/?span[^>]*>/g, '').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
        assert.strictEqual(back, code + ' ');
        assert.ok(painted.includes('class="enl-k0"'), painted);
        assert.ok(painted.includes('class="enl-line"'));
    }
};

let failed = 0;
for (const [name, test] of Object.entries(tests)) {
    try {
        test();
        console.log('  ok  ' + name);
    } catch (error) {
        failed++;
        console.log('  FAIL  ' + name + '\n        ' + error.message);
    }
}
console.log(failed ? '\n' + failed + ' of ' + Object.keys(tests).length + ' failed' : '\nOK (' + Object.keys(tests).length + ' tests)');
process.exitCode = failed ? 1 : 0;
