import { clsx, type ClassValue } from "clsx"
import { twMerge } from "tailwind-merge"

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

export function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.min(sizes.length - 1, Math.floor(Math.log(Math.abs(bytes)) / Math.log(k)))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

const avatarColors = [
  { bg: 'bg-emerald-500/15', text: 'text-emerald-700 dark:text-emerald-400' },
  { bg: 'bg-blue-500/15', text: 'text-blue-700 dark:text-blue-400' },
  { bg: 'bg-purple-500/15', text: 'text-purple-700 dark:text-purple-400' },
  { bg: 'bg-orange-500/15', text: 'text-orange-700 dark:text-orange-400' },
  { bg: 'bg-pink-500/15', text: 'text-pink-700 dark:text-pink-400' },
  { bg: 'bg-cyan-500/15', text: 'text-cyan-700 dark:text-cyan-400' },
]

/** Stable, theme-aware tint for a resource avatar, derived from its name. */
export function getAvatarColor(name: string) {
  let hash = 0
  for (let i = 0; i < name.length; i++) {
    hash = name.charCodeAt(i) + ((hash << 5) - hash)
  }
  return avatarColors[Math.abs(hash) % avatarColors.length]
}
