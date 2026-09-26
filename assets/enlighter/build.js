/**
 * Makes `enlighterjs.dpress.min.js`: the vendored EnlighterJS with the languages it lacks
 *
 *   node assets/enlighter/build.js
 *
 * EnlighterJS 3.4 has no way to add a language from outside - the table is a frozen object inside
 * the bundle's closure, and `init()` looks a name up in it and nowhere else. So the language goes
 * in: this reads `enlighterjs.min.js`, which stays exactly as released, and writes a copy with
 * `pascal.js` declared beside the built in languages and entered in the table.
 *
 * **It finds its way in by what the code does, not by the minifier's names.** The tokenizer is
 * whatever the generic language's `analyze()` calls, the shared rules are the object that starts
 * with `sqStrings`, and each anchor has to be found exactly once - so a different release of the
 * bundle stops this with an error rather than producing a copy that highlights nothing.
 *
 * Run it after changing `pascal.js`. `enlighter-pascal.test.js` fails while the copy on disk is
 * not what this would write.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const DIR = __dirname;
const LANGUAGES = {pascal: 'pascal.js'};

function once(bundle, pattern, what) {
    const found = bundle.match(new RegExp(pattern.source, 'g')) || [];
    if (found.length !== 1) {
        throw new Error(`build.js: expected ${what} once in enlighterjs.min.js, found it ${found.length} times`);
    }
    return bundle.match(pattern);
}

function build() {
    let bundle = fs.readFileSync(path.join(DIR, 'enlighterjs.min.js'), 'utf8');
    const tokenizer = once(bundle, /key:"analyze",value:function\(e\)\{return (\w+)\(e,this\.rules\)\}/, "the generic language's analyze()")[1];
    const rules = once(bundle, /var (\w+)=\{sqStrings:/, 'the shared rules')[1];
    once(bundle, /,he=Object\.freeze\(\{__proto__:null,generic:x,/, 'the language table');

    let declarations = '';
    let entries = '';
    Object.keys(LANGUAGES).forEach(name => {
        const source = fs.readFileSync(path.join(DIR, LANGUAGES[name]), 'utf8')
            .replace(/^\/\*[\s\S]*?\*\/\s*/, '')   // its header is for the source, not the bundle
            .trim();
        const variable = 'dpress_' + name;
        declarations += `,${variable}=(${source})(${tokenizer},${rules})`;
        entries += `${name}:${variable},`;
    });
    bundle = bundle.replace(',he=Object.freeze({__proto__:null,generic:x,',
        `${declarations},he=Object.freeze({__proto__:null,generic:x,${entries}`);

    return '/*! EnlighterJS 3.4.0, with the languages dpress adds (' + Object.keys(LANGUAGES).join(', ')
        + ') - made by assets/enlighter/build.js from the unmodified enlighterjs.min.js */\n' + bundle;
}

module.exports = {build, OUTPUT: path.join(DIR, 'enlighterjs.dpress.min.js')};

if (require.main === module) {
    fs.writeFileSync(module.exports.OUTPUT, build());
    console.log('wrote ' + path.relative(process.cwd(), module.exports.OUTPUT));
}
