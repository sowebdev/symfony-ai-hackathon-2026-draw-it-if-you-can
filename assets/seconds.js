// A number of milliseconds as seconds with one decimal, written the way of the page's language.
export function seconds(ms) {
    return (ms / 1000).toLocaleString(document.documentElement.lang, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
}
