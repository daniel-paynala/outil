"use client";

import { useEffect, type ReactNode } from "react";

/**
 * Le panneau latéral commun aux deux formulaires de supervision.
 *
 * Un panneau plutôt qu'une boîte centrée : on écrit ici une requête SQL et une
 * liste de paliers en gardant l'écran sous les yeux — c'est en voyant les
 * sondes existantes qu'on écrit la suivante.
 */
export default function Drawer({
  title,
  large = false,
  onClose,
  children,
}: {
  title: string;
  /**
   * Pour les formulaires à plusieurs colonnes.
   *
   * L'éditeur de sonde aligne durée, découpage, sens et seuils sur une même
   * ligne : dans la largeur ordinaire, le dernier champ se retrouve écrasé et
   * une liste de six seuils n'y tient plus.
   */
  large?: boolean;
  onClose: () => void;
  children: ReactNode;
}) {
  // Échap ferme. Un panneau qui se referme au clavier évite d'aller chercher la
  // souris pour abandonner une saisie.
  useEffect(() => {
    function surTouche(e: KeyboardEvent) {
      if (e.key === "Escape") onClose();
    }

    window.addEventListener("keydown", surTouche);
    return () => window.removeEventListener("keydown", surTouche);
  }, [onClose]);

  return (
    <div className="fixed inset-0 z-50 flex">
      <div
        className="flex-1 bg-black/20"
        onClick={onClose}
        aria-hidden="true"
      />
      <aside
        role="dialog"
        aria-modal="true"
        aria-label={title}
        className={`flex w-full flex-col border-l border-[var(--border)] bg-[var(--background)] shadow-2xl ${
          large ? "max-w-3xl" : "max-w-xl"
        }`}
      >
        <header className="flex items-center border-b border-[var(--border)] px-6 py-4">
          <h3 className="flex-1 text-sm font-medium">{title}</h3>
          <button
            onClick={onClose}
            className="text-xs text-[var(--muted)] hover:text-[var(--foreground)]"
          >
            Fermer
          </button>
        </header>
        {children}
      </aside>
    </div>
  );
}
