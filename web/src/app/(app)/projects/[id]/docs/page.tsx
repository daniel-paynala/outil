import { BookOpen } from "lucide-react";

export default function DocsEmpty() {
  return (
    <div className="flex flex-col items-center justify-center h-full min-h-[40vh] text-center">
      <BookOpen
        size={28}
        strokeWidth={1.5}
        className="text-[var(--muted)] mb-3"
      />
      <p className="text-sm">Documentation du projet</p>
      <p className="mt-1 text-xs text-[var(--muted)] max-w-sm">
        Sélectionne une page dans l'arborescence, ou crée-en une via le bouton{" "}
        <span className="font-medium">+</span>.
      </p>
    </div>
  );
}
