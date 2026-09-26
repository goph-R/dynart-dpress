/*
 * Pascal for EnlighterJS - Turbo Pascal, Borland Pascal, Free Pascal and Delphi
 *
 * Not loaded on its own: `build.js` puts it inside a copy of the vendored bundle, because
 * EnlighterJS 3.4 keeps its languages in a table nothing outside the bundle can reach. So this
 * is ES5, and it is a function of the two things it needs from in there - the tokenizer and the
 * shared rules - rather than a reference to their minified names.
 *
 * **How the tokenizer reads these rules.** Every rule is run over the whole text; the matches are
 * sorted by where they start, and a match starting inside one already taken is dropped. Two that
 * start at the same place go to the rule listed first. So the order below is the precedence:
 * a directive before the comment it looks like, a comment and a string before anything that
 * could match inside one.
 *
 * Case-insensitive throughout, as Pascal is: `BEGIN`, `Begin` and `begin` are one keyword.
 */
function (tokenize, rules) {

    function words(list) {
        return new RegExp('\\b(' + list.join('|') + ')\\b', 'gi');
    }

    function Pascal() {
        this.rules = [
            // {$I+}, {$DEFINE DEBUG}, (*$R-*) - compiler directives, set apart from comments
            {regex: /\{\$[^}]*\}|\(\*\$[\s\S]*?\*\)/g, type: 'k9'},
            {regex: /\{[\s\S]*?\}/g, type: 'c1'},
            {regex: /\(\*[\s\S]*?\*\)/g, type: 'c1'},
            {regex: /\/\/.*$/gm, type: 'c0'},
            // 'it''s' - a quote is doubled, not escaped, and a string does not cross a line
            {regex: /'(?:[^'\r\n]|'')*'/g, type: 's0'},
            // #13#10, #$1B - characters by their code, glued to strings or on their own
            {regex: /#\$?[0-9a-f]+/gi, type: 's1'},
            {regex: /\$[0-9a-f]+\b/gi, type: 'n2'},
            {regex: /%[01]+\b/g, type: 'n3'},
            {regex: words(['true', 'false', 'nil']), type: 'e0'},
            // the flow of a program
            {regex: words([
                'begin', 'end', 'if', 'then', 'else', 'case', 'of', 'for', 'to', 'downto', 'do',
                'while', 'repeat', 'until', 'with', 'goto', 'exit', 'break', 'continue', 'halt',
                'try', 'except', 'finally', 'raise', 'on', 'asm'
            ]), type: 'k1'},
            // what declares something
            {regex: words([
                'program', 'unit', 'library', 'uses', 'interface', 'implementation',
                'initialization', 'finalization', 'procedure', 'function', 'constructor',
                'destructor', 'operator', 'var', 'const', 'type', 'label', 'record', 'object',
                'class', 'property', 'inherited', 'array', 'set', 'file', 'packed', 'absolute',
                'external', 'forward', 'virtual', 'override', 'abstract', 'overload', 'reintroduce',
                'private', 'protected', 'public', 'published', 'strict', 'far', 'near', 'assembler',
                'interrupt', 'inline', 'cdecl', 'stdcall', 'pascal', 'register', 'out', 'threadvar',
                'resourcestring', 'specialize', 'generic'
            ]), type: 'k2'},
            // the operators that are words
            {regex: words(['and', 'or', 'not', 'xor', 'div', 'mod', 'shl', 'shr', 'in', 'is', 'as']), type: 'k0'},
            // the built in types
            {regex: words([
                'integer', 'shortint', 'smallint', 'longint', 'int64', 'byte', 'word', 'longword',
                'cardinal', 'qword', 'boolean', 'bytebool', 'wordbool', 'longbool', 'char',
                'ansichar', 'widechar', 'string', 'shortstring', 'ansistring', 'widestring',
                'unicodestring', 'pchar', 'real', 'single', 'double', 'extended', 'comp',
                'currency', 'pointer', 'text', 'variant'
            ]), type: 'k5'},
            rules.fCalls,
            rules.floats,
            rules.int,
            {regex: /[[\]()]+/g, type: 'g1'},
            {regex: /:=|<>|<=|>=|[@^]/g, type: 'g0'}
        ];
    }

    Pascal.alias = function () {
        return ['pas', 'delphi', 'objectpascal', 'freepascal', 'fpc', 'turbopascal'];
    };

    Pascal.prototype.analyze = function (code) {
        return tokenize(code, this.rules);
    };

    return Pascal;
}
