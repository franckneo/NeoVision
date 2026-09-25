function setCookie(name, value, days) {
    const expires = new Date(Date.now() + days * 86400000).toUTCString();
    document.cookie = `${name}=${value}; expires=${expires}; path=/; SameSite=Lax`;
}
function getCookie(name) {
    return document.cookie
        .split("; ")
        .find(row => row.startsWith(name + "="))
        ?.split("=")[1];
}
function applyLogos(enabled) {
    document.querySelectorAll(".logo").forEach(img => {
        img.src = enabled ? "logonuit.png" : "neovision.png";
    });
}
function applyDarkMode() {
    const isDark = getCookie("darkMode") === "enabled";
    document.body.classList.toggle("dark-mode", isDark);
    applyLogos(isDark);
}
document.addEventListener("DOMContentLoaded", () => {
    applyDarkMode();
    initDarkMode("toggleDarkMode");
});
function initDarkMode(toggleId = "toggleDarkMode") {
    const btn = document.getElementById(toggleId);
    const isDark = getCookie("darkMode") === "enabled";
    
    if (btn) {
        btn.textContent = isDark ? "Mode 🌞" : "Mode 🌙";
        btn.onclick = () => {
            const willBeDark = !document.body.classList.contains("dark-mode");
            document.body.classList.toggle("dark-mode", willBeDark);
            btn.textContent = willBeDark ? "Mode 🌞" : "Mode 🌙";
            applyLogos(willBeDark);
            setCookie("darkMode", willBeDark ? "enabled" : "disabled", 365);
        };
    }
}
