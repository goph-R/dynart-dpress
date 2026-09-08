/**
 * The emoji picker for the markdown field
 *
 * An emoji is **text**, not a widget: what goes into the document is the character itself, which
 * is why this needs nothing on the server and nothing at render time. It is the same rule the
 * rest of the editor follows - the markdown is the truth - and it is the reason this can be a
 * picker rather than a shortcode. `SchemaService::CHARSET` is `utf8mb4` for exactly this: on
 * MySQL's three-byte `utf8` an emoji is not stored badly, it is stored as `????`.
 *
 * **The list is curated and written out**, not generated from a Unicode table. A generated one is
 * about 1,900 entries with names and would arrive as a blob nobody reads or reviews; this is the
 * few hundred somebody writing a blog post actually reaches for, in the order the Unicode groups
 * put them, and it is a diff a person can read when it changes.
 *
 * **No flags.** Not an oversight: a curated flag list means choosing which countries are on it,
 * and that is not a decision a CMS should make on a site owner's behalf. Anybody who wants one
 * can type it - the field takes any character.
 *
 * **No names, so no search.** A name table is the same size again as the emoji, and the browser
 * already announces each button by its Unicode name to a screen reader without one. If search
 * turns out to be wanted, that is what the table would be for.
 */
(function (global) {
    'use strict';

    var Dpress = global.Dpress || {};
    global.Dpress = Dpress;

    /**
     * Space separated, because no emoji contains a space and several contain everything else
     *
     * Splitting a run of emoji by *character* is the bug waiting here: a family is several code
     * points joined by a zero-width joiner and a heart is a character plus a variation selector,
     * so `Array.from()` would tear both into pieces that render as something else. A space is the
     * one separator none of them can contain.
     */
    var GROUPS = [
        {
            name: 'Smileys',
            items: '😀 😃 😄 😁 😆 😅 🤣 😂 🙂 🙃 😉 😊 😇 🥰 😍 🤩 😘 😗 😚 😙 😋 😛 😜 🤪 😝 🤑 '
                 + '🤗 🤭 🤫 🤔 🤐 🤨 😐 😑 😶 😏 😒 🙄 😬 😌 😔 😪 🤤 😴 😷 🤒 🤕 🤢 🤮 🤧 🥵 🥶 '
                 + '😵 🤯 🤠 🥳 😎 🤓 🧐 😕 😟 🙁 😮 😯 😲 😳 🥺 😦 😧 😨 😰 😥 😢 😭 😱 😖 😣 😞 '
                 + '😓 😩 😫 🥱 😤 😡 😠 🤬 😈 👿 💀 💩 🤡 👻 👽 🤖 😺 😸 😹 😻 😼 😽 🙀 😿 😾'
        },
        {
            name: 'People',
            items: '👋 🤚 ✋ 🖖 👌 🤏 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ 👍 👎 ✊ 👊 🤛 🤜 👏 🙌 👐 🤲 '
                 + '🤝 🙏 ✍️ 💪 🦾 🦵 🦶 👂 👃 🧠 🦷 👀 👁️ 👅 👄 💋 🧑 👶 🧒 👦 👧 🧓 👴 👵 🙍 🙎 '
                 + '🙅 🙆 💁 🙋 🧏 🙇 🤦 🤷 👮 🕵️ 💂 👷 🤴 👸 🧙 🧚 🧛 🧜 🧝 🎅 🤶 🦸 🦹 💃 🕺 👯 '
                 + '🧖 🧗 🏄 🚴 🚵 🏊 🏋️ 🤸 🤹 🧘 🛀 🛌 👪 👨‍👩‍👧 👩‍💻 👨‍💻 👩‍🎨 👨‍🎨 👩‍🍳 👨‍🍳'
        },
        {
            name: 'Nature',
            items: '🐶 🐱 🐭 🐹 🐰 🦊 🐻 🐼 🐨 🐯 🦁 🐮 🐷 🐸 🐵 🙈 🙉 🙊 🐒 🐔 🐧 🐦 🐤 🐣 🐥 🦆 '
                 + '🦅 🦉 🦇 🐺 🐗 🐴 🦄 🐝 🐛 🦋 🐌 🐞 🐜 🦂 🕷️ 🕸️ 🐢 🐍 🦎 🦖 🦕 🐙 🦑 🦐 🦞 🦀 '
                 + '🐡 🐠 🐟 🐬 🐳 🐋 🦈 🐊 🐅 🐆 🦓 🦍 🐘 🦏 🐪 🐫 🦒 🦘 🐄 🐎 🐖 🐑 🦙 🐐 🦌 🐕 '
                 + '🐈 🐓 🦃 🦚 🦜 🦢 🕊️ 🐇 🦝 🦡 🐁 🐿️ 🦔 🌵 🎄 🌲 🌳 🌴 🌱 🌿 ☘️ 🍀 🍃 🍂 🍁 🍄 '
                 + '🐚 🌾 💐 🌷 🌹 🥀 🌺 🌸 🌼 🌻 🌞 🌝 🌚 🌙 ⭐ 🌟 ⚡ ☄️ 💥 🔥 🌪️ 🌈 ☀️ ⛅ ☁️ '
                 + '🌧️ ⛈️ ❄️ ☃️ ⛄ 💧 💦 ☔ 🌊'
        },
        {
            name: 'Food',
            items: '🍏 🍎 🍐 🍊 🍋 🍌 🍉 🍇 🍓 🍈 🍒 🍑 🥭 🍍 🥥 🥝 🍅 🍆 🥑 🥦 🥬 🥒 🌶️ 🌽 🥕 🧄 '
                 + '🧅 🥔 🍠 🥐 🥯 🍞 🥖 🥨 🧀 🥚 🍳 🧈 🥞 🧇 🥓 🥩 🍗 🍖 🌭 🍔 🍟 🍕 🥪 🥙 🧆 🌮 '
                 + '🌯 🥗 🥘 🍝 🍜 🍲 🍛 🍣 🍱 🥟 🍤 🍙 🍚 🍥 🍢 🍡 🍧 🍨 🍦 🥧 🧁 🍰 🎂 🍮 🍭 🍬 '
                 + '🍫 🍿 🍩 🍪 🌰 🥜 🍯 🥛 ☕ 🍵 🧃 🥤 🍶 🍺 🍻 🥂 🍷 🥃 🍸 🍹 🧉 🍾 🧊 🥄 🍴 🍽️ '
                 + '🥣 🧂'
        },
        {
            name: 'Activity',
            items: '⚽ 🏀 🏈 ⚾ 🥎 🎾 🏐 🏉 🥏 🎱 🏓 🏸 🏒 🏑 🥍 🏏 🥅 ⛳ 🏹 🎣 🤿 🥊 🥋 🎽 🛹 ⛸️ '
                 + '🥌 🎿 🏂 🎯 🎮 🕹️ 🎰 🎲 🧩 ♟️ 🎭 🎨 🧵 🧶 🎼 🎤 🎧 🎷 🎺 🎸 🎻 🥁 🎬 🏆 🥇 🥈 '
                 + '🥉 🏅 🎖️ 🏵️ 🎗️ 🎫 🎟️ 🎪 🎊 🎉 🎈 🎁 🎀'
        },
        {
            name: 'Travel',
            items: '🚗 🚕 🚙 🚌 🏎️ 🚓 🚑 🚒 🚐 🚚 🚛 🚜 🛴 🚲 🛵 🏍️ 🚨 🚡 🚠 🚃 🚋 🚞 🚄 🚅 🚈 🚂 '
                 + '🚆 🚇 🚊 🚉 ✈️ 🛫 🛬 🛩️ 💺 🛰️ 🚀 🛸 🚁 🛶 ⛵ 🚤 🛥️ 🛳️ ⛴️ 🚢 ⚓ ⛽ 🚧 🚦 🚥 '
                 + '🗺️ 🗿 🗽 🗼 🏰 🏯 🏟️ 🎡 🎢 🎠 ⛲ ⛱️ 🏖️ 🏝️ 🏜️ 🌋 ⛰️ 🏔️ 🗻 🏕️ ⛺ 🏠 🏡 🏘️ '
                 + '🏗️ 🏭 🏢 🏬 🏥 🏦 🏨 🏪 🏫 💒 🏛️ ⛪ 🕌 🕍 🛕 ⛩️ 🌁 🌃 🏙️ 🌄 🌅 🌆 🌇 🌉 🌌 '
                 + '🌠 🎇 🎆 🌏 🌍 🌎 🧭'
        },
        {
            name: 'Objects',
            items: '⌚ 📱 💻 ⌨️ 🖥️ 🖨️ 🖱️ 💾 💿 📀 📷 📸 📹 🎥 📽️ 📞 ☎️ 📠 📺 📻 🎙️ ⏱️ ⏲️ ⏰ '
                 + '🕰️ ⌛ ⏳ 📡 🔋 🔌 💡 🔦 🕯️ 🧯 💸 💵 💰 💳 🧾 💎 ⚖️ 🧰 🔧 🔨 ⚒️ 🛠️ ⛏️ 🔩 ⚙️ '
                 + '🧱 ⛓️ 🧲 💣 🧨 🔪 🗡️ ⚔️ 🛡️ 🏺 🔮 📿 🧿 ⚗️ 🔭 🔬 🕳️ 💊 💉 🩹 🩺 🌡️ 🧬 🦠 🧫 '
                 + '🧪 🧹 🧺 🧻 🚿 🛁 🧼 🧽 🔑 🗝️ 🚪 🪑 🛋️ 🛏️ 🧸 🖼️ 🛍️ 🛒 ✉️ 📩 📨 📧 💌 📥 📤 '
                 + '📦 🏷️ 📫 📮 📜 📃 📄 📑 🧮 📊 📈 📉 🗒️ 🗓️ 📆 📅 🗑️ 🗃️ 🗄️ 📋 📁 📂 🗂️ 🗞️ '
                 + '📰 📓 📔 📒 📕 📗 📘 📙 📚 📖 🔖 🔗 📎 🖇️ 📐 📏 📌 📍 ✂️ 🖊️ 🖋️ ✒️ 🖌️ 🖍️ 📝 '
                 + '✏️ 🔍 🔎 🔏 🔐 🔒 🔓'
        },
        {
            name: 'Symbols',
            items: '❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 ❣️ 💕 💞 💓 💗 💖 💘 💝 ☮️ ☯️ ⚛️ ♻️ ⚠️ 🚸 🔱 '
                 + '⚜️ 🔰 ✅ ❌ ⭕ 🛑 ⛔ 🚫 ✨ 💯 💢 ❗ ❕ ❓ ❔ ‼️ ⁉️ 🔅 🔆 ✔️ ☑️ 🆗 🆕 🆓 🆙 🆒 🔞 '
                 + '📵 🚭 ▶️ ⏸️ ⏹️ ⏭️ ⏮️ ⏩ ⏪ 🔀 🔁 🔂 ◀️ 🔼 🔽 ➡️ ⬅️ ⬆️ ⬇️ ↗️ ↘️ ↙️ ↖️ ↕️ ↔️ '
                 + '↩️ ↪️ 🔃 🔄 🔚 🔙 🔛 🔝 🔜 🔘 ⚪ ⚫ 🔴 🔵 🟠 🟡 🟢 🟣 🟤 🔺 🔻 🔶 🔷 🟥 🟧 🟨 '
                 + '🟩 🟦 🟪 🟫 ⬛ ⬜ ♠️ ♣️ ♥️ ♦️ 🃏 🎴 🔇 🔈 🔉 🔊 📢 📣 🔔 🔕 🎵 🎶 💭 🗯️ 💬 🗨️ '
                 + '♾️ 💤 🚩 🏁 🏳️ 🏴'
        }
    ];

    /**
     * The groups, each emoji paired with the words it can be found by
     *
     * Split once and kept, because the picker asks on every open and the search asks on every
     * keystroke. `Dpress.emojiWords` is a separate file and may not be there - a picker without a
     * search is still a working picker, so a missing words file costs the search and nothing else.
     */
    var groups = null;

    function all() {
        if (groups === null) {
            var words = (global.Dpress && global.Dpress.emojiWords) || {};
            groups = GROUPS.map(function (group) {
                return {
                    name: group.name,
                    items: group.items.split(' ')
                        .filter(function (one) { return one !== ''; })
                        .map(function (character) {
                            return {character: character, words: words[character] || ''};
                        })
                };
            });
        }
        return groups;
    }

    /**
     * Does one emoji answer to what somebody typed?
     *
     * **Every term, and each as the start of a word.** A substring match answers `art` with
     * `heart`, which reads as the picker not understanding the question; a prefix match answers
     * `hea` with `heart` and `art` with the palette, which is what a person expects of a search
     * box. Terms are *and*-ed so a second word narrows rather than widens - `red heart` is one
     * emoji and not every red thing plus every heart.
     */
    function matches(item, terms) {
        var words = item.words.split(' ');
        return terms.every(function (term) {
            return words.some(function (word) { return word.indexOf(term) === 0; });
        });
    }

    function termsOf(query) {
        return String(query === null || query === undefined ? '' : query)
            .toLowerCase().split(/\s+/)
            .filter(function (term) { return term !== ''; });
    }

    /**
     * The groups that still have something in them, given a query
     *
     * **Groups rather than a flat list, and a group with no hits is dropped whole.** The tab row
     * and the sections are then the same answer to the same question, so they cannot disagree
     * about which groups are showing - which is the entire trick behind hiding a tab when its
     * section has nothing in it.
     *
     * Exported because it is the part with the judgement in it and needs no DOM to test.
     */
    function search(query) {
        var wanted = termsOf(query);
        if (wanted.length === 0) {
            return all();
        }
        var found = [];
        all().forEach(function (group) {
            var items = group.items.filter(function (item) { return matches(item, wanted); });
            if (items.length > 0) {
                found.push({name: group.name, items: items});
            }
        });
        return found;
    }

    /**
     * Opens the picker and hands back the character somebody chose
     *
     * The same shape as `Dpress.pickMedia()` - a `<dialog>`, `showModal()`, and one way out
     * through the callback - so the two dialogs behave identically and Escape does what Escape
     * does without either implementing it.
     *
     * **One group while browsing, every matching group while searching.** Which is two shapes for
     * one dialog, and the reason is cost: a colour emoji is an image the browser paints, and 907
     * of them in a scrolling box is enough work per frame to be felt on a laptop - as lag with a
     * wheel, which animates about sixty frames a notch, and just under the threshold of noticing
     * with the scrollbar. A tab shows at most 155. It was tried as sections-with-headings first,
     * and the headings were tried sticky and then not; neither helped, because neither reduced
     * what had to be painted. **The number of glyphs on screen was the whole problem.**
     *
     * A search is different and can afford to be: it is a handful of hits across a few groups, so
     * it shows them all with a heading each, and the tab row becomes jumps into that list. The
     * groups with no hits leave both at once, because `search()` answers with groups and the row
     * and the sections are drawn from the same answer.
     */
    function pick(chosen) {
        var dialog = document.createElement('dialog');
        dialog.className = 'emoji-picker';
        dialog.innerHTML =
            '<header><h2>Emoji</h2><button type="button" class="close" title="Close">&times;</button></header>' +
            '<div class="emoji-search">' +
            '<input type="search" autofocus placeholder="Search…" aria-label="Search emoji">' +
            '</div>' +
            '<nav class="emoji-groups"></nav>' +
            '<div class="emoji-list"></div>';
        document.body.appendChild(dialog);

        var input = dialog.querySelector('input');
        var nav = dialog.querySelector('.emoji-groups');
        var list = dialog.querySelector('.emoji-list');
        /** Which group is showing when nothing is typed. Kept, so clearing a search comes back. */
        var current = 0;

        function close() {
            dialog.close();
            dialog.remove();
        }

        function gridOf(group) {
            var grid = document.createElement('div');
            grid.className = 'emoji-grid';
            group.items.forEach(function (item) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'emoji';
                button.title = item.words.split(' ')[0] || '';
                // No `aria-label`: a screen reader announces the character by its own Unicode
                // name, which is better than the one keyword written here would be.
                button.textContent = item.character;
                button.addEventListener('click', function () {
                    close();
                    chosen(item.character);
                });
                grid.appendChild(button);
            });
            return grid;
        }

        function tab(name) {
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = name;
            nav.appendChild(button);
            return button;
        }

        function draw(query) {
            var searching = termsOf(query).length > 0;
            nav.textContent = '';
            list.textContent = '';

            if (!searching) {
                // Browsing: the tabs are the state, and only the chosen group is in the document
                all().forEach(function (group, index) {
                    var button = tab(group.name);
                    button.classList.toggle('current', index === current);
                    button.setAttribute('aria-selected', index === current ? 'true' : 'false');
                    button.addEventListener('click', function () {
                        current = index;
                        draw('');
                    });
                });
                list.appendChild(gridOf(all()[current]));
                list.scrollTop = 0;
                return;
            }

            var found = search(query);
            if (found.length === 0) {
                var empty = document.createElement('p');
                empty.className = 'emoji-empty';
                empty.textContent = 'Nothing matches “' + query + '”.';
                list.appendChild(empty);
                return;
            }

            // Searching: every matching group at once, headed, and the tabs jump into them
            found.forEach(function (group) {
                var section = document.createElement('section');
                var heading = document.createElement('h3');
                heading.textContent = group.name;
                section.appendChild(heading);
                section.appendChild(gridOf(group));
                list.appendChild(section);

                var button = tab(group.name);
                // Scrolls the list, not the page. `scrollIntoView()` on a section inside a modal
                // moves the dialog itself in some browsers.
                button.addEventListener('click', function () {
                    list.scrollTop = section.offsetTop - list.offsetTop;
                    nav.querySelectorAll('button').forEach(function (other) {
                        other.classList.toggle('current', other === button);
                    });
                });
            });
            list.scrollTop = 0;
        }

        input.addEventListener('input', function () {
            draw(input.value);
        });

        dialog.querySelector('.close').addEventListener('click', close);
        dialog.addEventListener('cancel', function () {
            dialog.remove();   // Escape, which `<dialog>` gives us
        });
        draw('');
        dialog.showModal();
    }

    Dpress.emoji = {
        groups: all,
        search: search,
        pick: pick
    };

})(typeof window !== 'undefined' ? window : this);
