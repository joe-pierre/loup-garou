export function playerAvatarColor(id, colors) {
    const palette = colors ?? ['#c9a84c','#a78bfa','#4ade80','#ff4444','#38bdf8','#fb923c','#f472b6','#34d399'];
    return palette[id % palette.length];
}
