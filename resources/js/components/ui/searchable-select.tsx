import * as React from "react"
import { Check, ChevronsUpDown, Search, X } from "lucide-react"
import { cn } from "@/lib/utils"

export interface SearchableSelectOption {
  value: string
  label: string
  searchTerms?: string[]
}

interface SearchableSelectProps {
  options: SearchableSelectOption[]
  value?: string
  onValueChange?: (value: string) => void
  placeholder?: string
  searchPlaceholder?: string
  emptyMessage?: string
  className?: string
  disabled?: boolean
}

export function SearchableSelect({
  options,
  value,
  onValueChange,
  placeholder = "Selectează o opțiune",
  searchPlaceholder = "Caută...",
  emptyMessage = "Nu au fost găsite rezultate",
  className,
  disabled = false,
}: SearchableSelectProps) {
  const [open, setOpen] = React.useState(false)
  const [searchValue, setSearchValue] = React.useState("")
  const dropdownRef = React.useRef<HTMLDivElement>(null)
  const buttonRef = React.useRef<HTMLButtonElement>(null)

  const filteredOptions = React.useMemo(() => {
    if (!searchValue) return options

    const searchLower = searchValue.toLowerCase()
    return options.filter((option) => {
      // Search in label
      if (option.label && option.label.toLowerCase().includes(searchLower)) return true
      
      // Search in value
      if (option.value && option.value.toLowerCase().includes(searchLower)) return true
      
      // Search in additional search terms
      if (option.searchTerms) {
        return option.searchTerms.some(term => 
          term && term.toLowerCase().includes(searchLower)
        )
      }
      
      return false
    })
  }, [options, searchValue])

  const selectedOption = options.find(option => option.value === value)

  // Close dropdown when clicking outside
  React.useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (
        dropdownRef.current &&
        !dropdownRef.current.contains(event.target as Node) &&
        buttonRef.current &&
        !buttonRef.current.contains(event.target as Node)
      ) {
        setOpen(false)
      }
    }

    if (open) {
      document.addEventListener("mousedown", handleClickOutside)
    }

    return () => {
      document.removeEventListener("mousedown", handleClickOutside)
    }
  }, [open])

  const handleSelect = (optionValue: string) => {
    onValueChange?.(optionValue === value ? "" : optionValue)
    setOpen(false)
    setSearchValue("")
  }

  return (
    <div className="relative">
      <button
        ref={buttonRef}
        type="button"
        onClick={() => setOpen(!open)}
        disabled={disabled}
        className={cn(
          "flex h-8 w-full items-center justify-between rounded-md border border-muted-foreground/30 bg-background/50 px-3 py-1 text-sm shadow-sm transition-colors hover:bg-accent hover:text-accent-foreground focus:outline-none focus:ring-1 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50",
          !value && "text-muted-foreground",
          className
        )}
        aria-expanded={open}
        aria-haspopup="listbox"
      >
        <span className="text-left overflow-hidden text-ellipsis whitespace-nowrap flex-1 min-w-0">
          {selectedOption?.label || placeholder}
        </span>
        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
      </button>

      {open && (
        <div
          ref={dropdownRef}
          className="absolute z-50 mt-1 w-full min-w-[400px] rounded-md border bg-popover shadow-lg"
        >
          <div className="flex items-center border-b px-3">
            <Search className="mr-2 h-4 w-4 shrink-0 opacity-50" />
            <input
              type="text"
              className="flex h-8 w-full bg-transparent py-2 text-sm outline-none placeholder:text-muted-foreground"
              placeholder={searchPlaceholder}
              value={searchValue}
              onChange={(e) => setSearchValue(e.target.value)}
              autoFocus
            />
            {searchValue && (
              <button
                type="button"
                onClick={() => setSearchValue("")}
                className="ml-2 rounded-sm opacity-50 hover:opacity-100"
              >
                <X className="h-4 w-4" />
              </button>
            )}
          </div>

          <div className="max-h-[300px] overflow-y-auto p-1">
            {filteredOptions.length === 0 ? (
              <div className="py-6 text-center text-sm text-muted-foreground">
                {emptyMessage}
              </div>
            ) : (
              filteredOptions.map((option) => (
                <button
                  key={option.value}
                  type="button"
                  onClick={() => handleSelect(option.value)}
                  className={cn(
                    "relative flex w-full cursor-pointer select-none items-center rounded-sm px-2 py-2 text-sm outline-none hover:bg-accent hover:text-accent-foreground text-left",
                    value === option.value && "bg-accent text-accent-foreground"
                  )}
                >
                  <Check
                    className={cn(
                      "mr-2 h-4 w-4 flex-shrink-0",
                      value === option.value ? "opacity-100" : "opacity-0"
                    )}
                  />
                  <span className="flex-1 text-left leading-tight whitespace-nowrap overflow-hidden text-ellipsis">{option.label}</span>
                </button>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  )
}