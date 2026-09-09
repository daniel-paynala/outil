import { apiJson } from "@/lib/api/server";
import PhoneLinesTable, { type PhoneLine } from "./phone-lines-table";

export const dynamic = "force-dynamic";

export default async function PhoneLinesPage() {
  const lines = await apiJson<PhoneLine[]>("/api/phone-lines");

  const avecMobileMoney = lines.filter((l) => l.mobile_money).length;

  return (
    <div className="space-y-6">
      <header>
        <p className="text-xs uppercase tracking-wider text-[var(--muted)]">
          Entreprise
        </p>
        <h1 className="mt-1 text-2xl font-semibold tracking-tight">Numéros</h1>
        <p className="mt-2 text-sm text-[var(--muted)]">
          {lines.length} ligne{lines.length > 1 ? "s" : ""} au registre
          {avecMobileMoney > 0 && (
            <>
              , dont {avecMobileMoney} rattachée
              {avecMobileMoney > 1 ? "s" : ""} à un compte Mobile Money
            </>
          )}
          . Chacun ajoute et corrige : un registre qu&apos;il faut faire
          modifier par quelqu&apos;un d&apos;autre cesse vite d&apos;être à
          jour. Chaque ligne garde en revanche la trace de qui l&apos;a écrite.
        </p>
      </header>

      <PhoneLinesTable lines={lines} />
    </div>
  );
}
