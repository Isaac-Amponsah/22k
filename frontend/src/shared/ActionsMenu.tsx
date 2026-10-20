// The actions of one row of a list, behind a menu icon: a click opens them as a dropdown. The dropdown is
// rendered on <body> at the icon's place, so a table's scrolling box cannot clip it.

import { useEffect, useLayoutEffect, useRef, useState } from "react";
import type { CSSProperties, KeyboardEvent } from "react";
import { createPortal } from "react-dom";
import { Link } from "react-router-dom";

type ActionTarget =
  | { onSelect: () => void }
  /** A page of the app. */
  | { to: string; opensInNewTab?: boolean }
  /** A server address the browser fetches itself, such as a file download. */
  | { href: string };

export type MenuAction = ActionTarget & {
  label: string;
  isDangerous?: boolean;
  isDisabled?: boolean;
};

const MENU_GAP_PIXELS = 4;

const ITEM_CLASSES =
  "block w-full px-3.5 py-2 text-left text-sm whitespace-nowrap outline-none hover:bg-brand-tint focus-visible:bg-brand-tint";

interface ActionsMenuProps {
  /** What the menu acts on, for screen readers: "Actions for Ama Mensah". */
  label: string;
  actions: MenuAction[];
}

export function ActionsMenu({ label, actions }: ActionsMenuProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [menuPosition, setMenuPosition] = useState<CSSProperties>({});
  const triggerElement = useRef<HTMLButtonElement>(null);
  const menuElement = useRef<HTMLDivElement>(null);

  function closeMenu(returnFocus: boolean) {
    setIsOpen(false);
    if (returnFocus) {
      triggerElement.current?.focus();
    }
  }

  // Place the menu under the icon, right edges aligned; above it when there is no room below.
  useLayoutEffect(() => {
    if (!isOpen || !triggerElement.current || !menuElement.current) {
      return;
    }
    const triggerBox = triggerElement.current.getBoundingClientRect();
    const menuHeight = menuElement.current.offsetHeight;
    const fitsBelow = triggerBox.bottom + MENU_GAP_PIXELS + menuHeight <= window.innerHeight;
    setMenuPosition({
      right: Math.max(window.innerWidth - triggerBox.right, MENU_GAP_PIXELS),
      top: fitsBelow ? triggerBox.bottom + MENU_GAP_PIXELS : Math.max(triggerBox.top - MENU_GAP_PIXELS - menuHeight, MENU_GAP_PIXELS),
    });
    menuElement.current.querySelector<HTMLElement>("[role=menuitem]:not([aria-disabled=true])")?.focus();
  }, [isOpen]);

  // A click elsewhere closes it; so does scrolling or resizing, which would leave it floating off its row.
  useEffect(() => {
    if (!isOpen) {
      return;
    }
    function closeOnOutsidePointer(pointerEvent: PointerEvent) {
      const pointedAt = pointerEvent.target as Node;
      if (!menuElement.current?.contains(pointedAt) && !triggerElement.current?.contains(pointedAt)) {
        setIsOpen(false);
      }
    }
    function closeOnMove(moveEvent: Event) {
      if (!menuElement.current?.contains(moveEvent.target as Node)) {
        setIsOpen(false);
      }
    }
    document.addEventListener("pointerdown", closeOnOutsidePointer);
    window.addEventListener("scroll", closeOnMove, true);
    window.addEventListener("resize", closeOnMove);
    return () => {
      document.removeEventListener("pointerdown", closeOnOutsidePointer);
      window.removeEventListener("scroll", closeOnMove, true);
      window.removeEventListener("resize", closeOnMove);
    };
  }, [isOpen]);

  function handleMenuKey(keyEvent: KeyboardEvent<HTMLDivElement>) {
    if (keyEvent.key === "Escape") {
      keyEvent.preventDefault();
      closeMenu(true);
      return;
    }
    if (keyEvent.key === "Tab") {
      closeMenu(false);
      return;
    }
    if (keyEvent.key !== "ArrowDown" && keyEvent.key !== "ArrowUp") {
      return;
    }
    keyEvent.preventDefault();
    const menuItems = Array.from(menuElement.current?.querySelectorAll<HTMLElement>("[role=menuitem]:not([aria-disabled=true])") ?? []);
    const focusedIndex = menuItems.indexOf(document.activeElement as HTMLElement);
    const step = keyEvent.key === "ArrowDown" ? 1 : -1;
    menuItems[(focusedIndex + step + menuItems.length) % menuItems.length]?.focus();
  }

  function itemOf(action: MenuAction) {
    const itemClasses = `${ITEM_CLASSES} ${action.isDangerous ? "text-refusal hover:bg-refusal-tint focus-visible:bg-refusal-tint" : "text-ink"}`;
    if (action.isDisabled) {
      return (
        <span role="menuitem" aria-disabled="true" className={`${ITEM_CLASSES} cursor-not-allowed text-ink-soft/50 hover:bg-transparent`}>
          {action.label}
        </span>
      );
    }
    if ("onSelect" in action) {
      return (
        <button
          type="button"
          role="menuitem"
          className={itemClasses}
          onClick={() => {
            closeMenu(true);
            action.onSelect();
          }}
        >
          {action.label}
        </button>
      );
    }
    if ("to" in action) {
      return (
        <Link
          role="menuitem"
          to={action.to}
          target={action.opensInNewTab ? "_blank" : undefined}
          className={itemClasses}
          onClick={() => closeMenu(false)}
        >
          {action.label}
        </Link>
      );
    }
    return (
      <a role="menuitem" href={action.href} className={itemClasses} onClick={() => closeMenu(false)}>
        {action.label}
      </a>
    );
  }

  return (
    <>
      <button
        ref={triggerElement}
        type="button"
        aria-label={label}
        aria-haspopup="menu"
        aria-expanded={isOpen}
        onClick={() => (isOpen ? closeMenu(false) : setIsOpen(true))}
        className={`inline-flex h-8 w-8 items-center justify-center rounded-md text-ink-soft transition-colors hover:bg-brand-tint hover:text-ink ${isOpen ? "bg-brand-tint text-ink" : ""}`}
      >
        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" className="h-5 w-5">
          <circle cx="10" cy="4" r="1.6" />
          <circle cx="10" cy="10" r="1.6" />
          <circle cx="10" cy="16" r="1.6" />
        </svg>
      </button>
      {isOpen
        ? createPortal(
            <div
              ref={menuElement}
              role="menu"
              aria-label={label}
              onKeyDown={handleMenuKey}
              style={{ position: "fixed", ...menuPosition }}
              className="z-50 min-w-40 overflow-hidden rounded-md border border-rule bg-surface py-1 shadow-card"
            >
              {actions.map((action) => (
                <div key={action.label} role="none">
                  {itemOf(action)}
                </div>
              ))}
            </div>,
            document.body,
          )
        : null}
    </>
  );
}
