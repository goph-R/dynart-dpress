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
                 + '🐚 🌾 💐 🌷 🌹 🥀 🌺 🌸 🌼 🌻 🌞 🌝 🌚 🌙 ⭐ 🌟 ✨ ⚡ ☄️ 💥 🔥 🌪️ 🌈 ☀️ ⛅ ☁️ '
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
                 + '⚜️ 🔰 ✅ ❌ ⭕ 🛑 ⛔ 🚫 💯 💢 ❗ ❕ ❓ ❔ ‼️ ⁉️ 🔅 🔆 ✔️ ☑️ 🆗 🆕 🆓 🆙 🆒 🔞 '
                 + '📵 🚭 ▶️ ⏸️ ⏹️ ⏭️ ⏮️ ⏩ ⏪ 🔀 🔁 🔂 ◀️ 🔼 🔽 ➡️ ⬅️ ⬆️ ⬇️ ↗️ ↘️ ↙️ ↖️ ↕️ ↔️ '
                 + '↩️ ↪️ 🔃 🔄 🔚 🔙 🔛 🔝 🔜 🔘 ⚪ ⚫ 🔴 🔵 🟠 🟡 🟢 🟣 🟤 🔺 🔻 🔶 🔷 🟥 🟧 🟨 '
                 + '🟩 🟦 🟪 🟫 ⬛ ⬜ ♠️ ♣️ ♥️ ♦️ 🃏 🎴 🔇 🔈 🔉 🔊 📢 📣 🔔 🔕 🎵 🎶 💭 🗯️ 💬 🗨️ '
                 + '♾️ 💤 🚩 🏁 🏳️ 🏴'
        }
    ];

    /**
     * The groups, with each list split once rather than on every open
     */
    var groups = null;

    function all() {
        if (groups === null) {
            groups = GROUPS.map(function (group) {
                return {
                    name: group.name,
                    items: group.items.split(' ').filter(function (one) { return one !== ''; })
                };
            });
        }
        return groups;
    }

    /**
     * Opens the picker and hands back the character somebody chose
     *
     * The same shape as `Dpress.pickMedia()` - a `<dialog>`, `showModal()`, and one way out
     * through the callback - so the two dialogs behave identically and Escape does what Escape
     * does without either of them implementing it.
     *
     * **One group at a time.** Sections in one long scroll would have every group a scroll away
     * from every other; a row of buttons puts each of them one click away, which is the reason
     * the admin's own sections are a rail rather than a page.
     */
    function pick(chosen) {
        var dialog = document.createElement('dialog');
        dialog.className = 'emoji-picker';
        dialog.innerHTML =
            '<header><h2>Emoji</h2><button type="button" class="close" title="Close">&times;</button></header>' +
            '<nav class="emoji-groups"></nav>' +
            '<div class="emoji-list"></div>';
        document.body.appendChild(dialog);

        var nav = dialog.querySelector('.emoji-groups');
        var list = dialog.querySelector('.emoji-list');

        function show(index) {
            nav.querySelectorAll('button').forEach(function (button, at) {
                button.classList.toggle('current', at === index);
                button.setAttribute('aria-selected', at === index ? 'true' : 'false');
            });
            list.textContent = '';
            all()[index].items.forEach(function (character) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'emoji';
                // No `aria-label`: a screen reader announces the character by its own Unicode
                // name, which is better than any short label written here would be.
                button.textContent = character;
                button.addEventListener('click', function () {
                    close();
                    chosen(character);
                });
                list.appendChild(button);
            });
            list.scrollTop = 0;
        }

        all().forEach(function (group, index) {
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = group.name;
            button.setAttribute('role', 'tab');
            if (index === 0) {
                // `showModal()` focuses the first focusable thing it finds, which is the close
                // button - a keyboard lands on *leave* rather than on the list it just opened.
                button.autofocus = true;
            }
            button.addEventListener('click', function () {
                show(index);
            });
            nav.appendChild(button);
        });
        nav.setAttribute('role', 'tablist');

        function close() {
            dialog.close();
            dialog.remove();
        }

        dialog.querySelector('.close').addEventListener('click', close);
        dialog.addEventListener('cancel', function () {
            dialog.remove();   // Escape, which `<dialog>` gives us
        });
        show(0);
        dialog.showModal();
    }

    Dpress.emoji = {
        groups: all,
        pick: pick
    };

})(typeof window !== 'undefined' ? window : this);
