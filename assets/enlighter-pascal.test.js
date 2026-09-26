/**
 * The Pascal language dpress adds to EnlighterJS, with no toolchain at all
 *
 *   node assets/enlighter-pascal.test.js
 *
 * Runs the **built** bundle, the file a page loads, rather than `pascal.js` on its own - what is
 * worth knowing is that the language is in the table and answers to its name, and only the
 * bundle can say that. The one liberty taken is exposing the table, which the bundle keeps to
 * itself; the test adds that to its own copy in memory.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('assert');
const {build, OUTPUT} = require('./enlighter/build.js');

global.window = global;
global.document = {};
// indirect, so the bundle's `var EnlighterJS` lands in the global scope rather than this strict one
(0, eval)(fs.readFileSync(OUTPUT, 'utf8').replace('e.version="3.4.0"', 'e.version="3.4.0",e.languages=he'));
const Pascal = EnlighterJS.languages.pascal;

/** Every token that is not plain text, as `type:text` */
function tokens(code) {
    return new Pascal().analyze(code)
        .filter(token => token.type !== 'text' && token.text.trim() !== '')
        .map(token => token.type + ':' + token.text);
}

function typed(code, type) {
    return tokens(code).filter(t => t.startsWith(type + ':')).map(t => t.slice(type.length + 1));
}

const tests = {

    'the bundle on disk is what build.js makes - run it after changing pascal.js'() {
        // line endings aside: a Windows checkout may have turned them into CRLF
        const lf = text => text.replace(/\r\n/g, '\n');
        assert.ok(lf(fs.readFileSync(OUTPUT, 'utf8')) === lf(build()), 'enlighterjs.dpress.min.js is stale');
    },

    'the vendored bundle is left as released'() {
        const upstream = fs.readFileSync(path.join(__dirname, 'enlighter', 'enlighterjs.min.js'), 'utf8');
        assert.ok(upstream.startsWith('/*! EnlighterJS Syntax Highlighter 3.4.0'));
        assert.ok(!upstream.includes('dpress'));
    },

    'keywords are keywords in any case'() {
        assert.deepStrictEqual(typed('BEGIN Begin begin END', 'k1'), ['BEGIN', 'Begin', 'begin', 'END']);
        assert.deepStrictEqual(typed('procedure P; var x: Integer;', 'k2'), ['procedure', 'var']);
        assert.deepStrictEqual(typed('a div b mod c and not d', 'k0'), ['div', 'mod', 'and', 'not']);
        assert.deepStrictEqual(typed('x: Word; y: LongInt;', 'k5'), ['Word', 'LongInt']);
    },

    'a keyword inside a longer name is not one'() {
        assert.deepStrictEqual(typed('beginning := endless + ender', 'k1'), []);
    },

    'the three comments, and a keyword inside one stays comment'() {
        assert.deepStrictEqual(tokens('{ begin end }'), ['c1:{ begin end }']);
        assert.deepStrictEqual(tokens('(* if then *)'), ['c1:(* if then *)']);
        assert.deepStrictEqual(tokens('x := 1; // begin'), ['g0::=', 'n1:1', 'c0:// begin']);
    },

    'a comment runs across lines'() {
        assert.deepStrictEqual(tokens('{ one\n  two }'), ['c1:{ one\n  two }']);
    },

    'a directive is not a comment'() {
        assert.deepStrictEqual(tokens('{$I+}'), ['k9:{$I+}']);
        assert.deepStrictEqual(tokens('{$DEFINE DEBUG}'), ['k9:{$DEFINE DEBUG}']);
        assert.deepStrictEqual(tokens('(*$R-*)'), ['k9:(*$R-*)']);
    },

    'a string doubles its quote, and a brace in one is not a comment'() {
        assert.deepStrictEqual(typed("s := 'it''s { not } a comment';", 's0'), ["'it''s { not } a comment'"]);
        assert.deepStrictEqual(typed("s := 'a'; t := 'b';", 's0'), ["'a'", "'b'"]);
    },

    'characters by their code'() {
        assert.deepStrictEqual(typed("'line'#13#10#$1B", 's1'), ['#13', '#10', '#$1B']);
    },

    'numbers: hex with a dollar, binary with a percent, and the plain ones'() {
        assert.deepStrictEqual(typed('Port[$3C8] := $FF;', 'n2'), ['$3C8', '$FF']);
        assert.deepStrictEqual(typed('m := %1010;', 'n3'), ['%1010']);
        assert.deepStrictEqual(typed('x := 320 * 200;', 'n1'), ['320', '200']);
        assert.deepStrictEqual(typed('r := 1.5;', 'n0'), ['1.5']);
    },

    'true, false and nil'() {
        assert.deepStrictEqual(typed('if p = nil then ok := False', 'e0'), ['nil', 'False']);
    },

    'a call is a call, and a keyword before a bracket stays a keyword'() {
        assert.deepStrictEqual(typed('WriteLn(x); if (a) then', 'm0'), ['WriteLn']);
    },

    'it answers to the names Delphi and Free Pascal are written as'() {
        assert.deepStrictEqual(Pascal.alias(), ['pas', 'delphi', 'objectpascal', 'freepascal', 'fpc', 'turbopascal']);
    },

    'a real unit from the engine goes through without a gap'() {
        const code = [
            'unit VGA;',
            '{$G+}',
            'interface',
            'procedure SetMode(Mode: Byte);',
            'implementation',
            'procedure SetMode(Mode: Byte); assembler;',
            'asm',
            '  mov ah, 0',
            '  mov al, Mode',
            '  int $10',
            'end;',
            'end.'
        ].join('\n');
        const all = new Pascal().analyze(code);
        assert.strictEqual(all.map(token => token.text).join(''), code, 'the tokens do not add up to the code');
    }
};

// --- runner ---

let failed = 0;
Object.keys(tests).forEach(name => {
    try {
        tests[name]();
        console.log('  ok  ' + name);
    } catch (error) {
        failed++;
        console.log('  FAIL  ' + name);
        console.log('        ' + error.message);
    }
});
const count = Object.keys(tests).length;
console.log(failed ? `\n${failed} of ${count} failed` : `\nOK (${count} tests)`);
process.exit(failed ? 1 : 0);
