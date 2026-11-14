/* Dark mode for the dashboard and admin pages.
   Saves the choice in the browser (localStorage) so it is remembered. */
(function () {
    var KEY = "theme";
    var root = document.documentElement;

    var MOON = '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>';
    var SUN = '<circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>';

    function getSaved() {
        try { return localStorage.getItem(KEY); } catch (e) { return null; }
    }
    function save(value) {
        try { localStorage.setItem(KEY, value); } catch (e) {}
    }

    // Apply right away (before the page paints) to avoid a white flash
    if (getSaved() === "dark") root.classList.add("dark");

    document.addEventListener("DOMContentLoaded", function () {
        var btn = document.createElement("button");
        btn.type = "button";
        btn.className = "theme-toggle";
        btn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"></svg>';
        var svg = btn.firstChild;

        function render() {
            var dark = root.classList.contains("dark");
            svg.innerHTML = dark ? SUN : MOON;
            var label = dark ? "Switch to light mode" : "Switch to dark mode";
            btn.title = label;
            btn.setAttribute("aria-label", label);
        }

        btn.addEventListener("click", function () {
            var dark = root.classList.toggle("dark");
            save(dark ? "dark" : "light");
            render();
        });

        var bar = document.querySelector(".top-header-bar");
        if (bar) {
            bar.appendChild(btn);
        } else {
            btn.classList.add("theme-toggle-floating");
            document.body.appendChild(btn);
        }
        render();
    });
})();