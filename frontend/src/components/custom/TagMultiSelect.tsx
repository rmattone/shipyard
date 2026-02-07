import { useRef, useState } from "react"
import { Check, ChevronDown } from "lucide-react"
import { cn } from "@/lib/utils"
import { Tag } from "@/services/api"
import { TagBadge } from "./TagBadge"

interface TagMultiSelectProps {
  tags: Tag[]
  selectedIds: number[]
  onChange: (ids: number[]) => void
  placeholder?: string
  disabled?: boolean
}

export function TagMultiSelect({
  tags,
  selectedIds,
  onChange,
  placeholder = "Select tags...",
  disabled = false,
}: TagMultiSelectProps) {
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)

  const selectedTags = tags.filter((tag) => selectedIds.includes(tag.id))

  const toggleTag = (tagId: number) => {
    if (selectedIds.includes(tagId)) {
      onChange(selectedIds.filter((id) => id !== tagId))
    } else {
      onChange([...selectedIds, tagId])
    }
  }

  const removeTag = (tagId: number) => {
    onChange(selectedIds.filter((id) => id !== tagId))
  }

  return (
    <div className="relative" ref={containerRef}>
      <div
        className={cn(
          "min-h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm",
          "flex flex-wrap gap-1 items-center cursor-pointer",
          disabled && "opacity-50 cursor-not-allowed",
          open && "ring-2 ring-ring ring-offset-2"
        )}
        onClick={() => !disabled && setOpen(!open)}
      >
        {selectedTags.length > 0 ? (
          selectedTags.map((tag) => (
            <TagBadge
              key={tag.id}
              tag={tag}
              onRemove={() => removeTag(tag.id)}
            />
          ))
        ) : (
          <span className="text-muted-foreground">{placeholder}</span>
        )}
        <ChevronDown
          className={cn(
            "ml-auto h-4 w-4 shrink-0 opacity-50 transition-transform",
            open && "rotate-180"
          )}
        />
      </div>

      {open && !disabled && (
        <>
          <div
            className="fixed inset-0 z-40"
            onClick={() => setOpen(false)}
          />
          <div className="absolute z-50 mt-1 w-full rounded-md border bg-popover shadow-md">
            {tags.length === 0 ? (
              <div className="p-4 text-center text-sm text-muted-foreground">
                No tags available. Create tags in server settings.
              </div>
            ) : (
              <div className="max-h-60 overflow-auto p-1">
                {tags.map((tag) => {
                  const isSelected = selectedIds.includes(tag.id)
                  return (
                    <div
                      key={tag.id}
                      className={cn(
                        "flex items-center gap-2 px-2 py-1.5 rounded-sm cursor-pointer",
                        "hover:bg-accent hover:text-accent-foreground",
                        isSelected && "bg-accent/50"
                      )}
                      onClick={() => toggleTag(tag.id)}
                    >
                      <div
                        className={cn(
                          "flex h-4 w-4 items-center justify-center rounded-sm border",
                          isSelected
                            ? "bg-primary border-primary text-primary-foreground"
                            : "border-input"
                        )}
                      >
                        {isSelected && <Check className="h-3 w-3" />}
                      </div>
                      <TagBadge tag={tag} />
                    </div>
                  )
                })}
              </div>
            )}
          </div>
        </>
      )}
    </div>
  )
}
