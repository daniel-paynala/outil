"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { Loader2, Mail, KeyRound, User as UserIcon, BellRing, Camera, Trash2 } from "lucide-react";
import { apiFetch } from "@/lib/api/client";
import { createClient } from "@/lib/supabase/client";
import { useToast } from "@/core/toast/toast-context";
import Avatar from "@/core/ui/avatar";

type MeResponse = {
  user: {
    id: string;
    email: string;
    name: string | null;
    role: "admin" | "member";
    avatar_url?: string | null;
    notify_task_assignment_email?: boolean;
    notify_project_document_email?: boolean;
  } | null;
};

export default function AccountClient() {
  const toast = useToast();
  const [loading, setLoading] = useState(true);
  const [me, setMe] = useState<MeResponse["user"] | null>(null);

  // Profile form
  const [name, setName] = useState("");
  const [savingName, setSavingName] = useState(false);

  // Avatar
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [savingAvatar, setSavingAvatar] = useState(false);

  // Notifications
  const [notifyTask, setNotifyTask] = useState(false);
  const [notifyDoc, setNotifyDoc] = useState(false);
  const [savingNotif, setSavingNotif] = useState(false);

  // Password
  const [currentPwd, setCurrentPwd] = useState("");
  const [newPwd, setNewPwd] = useState("");
  const [confirmPwd, setConfirmPwd] = useState("");
  const [savingPwd, setSavingPwd] = useState(false);
  const [pwdError, setPwdError] = useState<string | null>(null);

  useEffect(() => {
    (async () => {
      try {
        const res = await apiFetch("/api/me");
        if (res.ok) {
          const body = (await res.json()) as MeResponse;
          if (body.user) {
            setMe(body.user);
            setName(body.user.name ?? "");
            setNotifyTask(body.user.notify_task_assignment_email ?? false);
            setNotifyDoc(body.user.notify_project_document_email ?? false);
          }
        }
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  async function uploadAvatar(file: File) {
    setSavingAvatar(true);
    try {
      const fd = new FormData();
      fd.append("file", file);
      const res = await apiFetch("/api/me/avatar", {
        method: "POST",
        body: fd,
      });
      if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        const msg =
          body.message ??
          (body.errors?.file?.[0] ?? `HTTP ${res.status}`);
        throw new Error(msg);
      }
      const updated = await res.json();
      setMe(updated);
      toast.success("Photo mise à jour");
    } catch (err) {
      toast.error(
        "Upload impossible",
        err instanceof Error ? err.message : "Erreur",
      );
    } finally {
      setSavingAvatar(false);
      if (fileInputRef.current) fileInputRef.current.value = "";
    }
  }

  async function removeAvatar() {
    if (!confirm("Retirer ta photo de profil ?")) return;
    setSavingAvatar(true);
    try {
      const res = await apiFetch("/api/me/avatar", { method: "DELETE" });
      if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.message ?? `HTTP ${res.status}`);
      }
      const updated = await res.json();
      setMe(updated);
      toast.success("Photo retirée");
    } catch (err) {
      toast.error(
        "Suppression impossible",
        err instanceof Error ? err.message : "Erreur",
      );
    } finally {
      setSavingAvatar(false);
    }
  }

  async function saveName(e: FormEvent) {
    e.preventDefault();
    setSavingName(true);
    try {
      const res = await apiFetch("/api/me/settings", {
        method: "PATCH",
        body: JSON.stringify({ name: name.trim() || null }),
      });
      if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.message ?? `HTTP ${res.status}`);
      }
      const updated = await res.json();
      setMe(updated);
      toast.success("Profil enregistré");
    } catch (err) {
      toast.error(
        "Enregistrement impossible",
        err instanceof Error ? err.message : "Erreur",
      );
    } finally {
      setSavingName(false);
    }
  }

  async function toggleNotif(
    field: "notify_task_assignment_email" | "notify_project_document_email",
    next: boolean,
    rollback: (v: boolean) => void,
    apply: (v: boolean) => void,
  ) {
    apply(next); // optimistic
    setSavingNotif(true);
    try {
      const res = await apiFetch("/api/me/settings", {
        method: "PATCH",
        body: JSON.stringify({ [field]: next }),
      });
      if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.message ?? `HTTP ${res.status}`);
      }
      toast.success(
        next
          ? "Notification email activée"
          : "Notification email désactivée",
      );
    } catch (err) {
      rollback(!next); // restaure
      toast.error(
        "Mise à jour impossible",
        err instanceof Error ? err.message : "Erreur",
      );
    } finally {
      setSavingNotif(false);
    }
  }

  async function changePassword(e: FormEvent) {
    e.preventDefault();
    setPwdError(null);

    if (newPwd.length < 12) {
      setPwdError("Le nouveau mot de passe doit faire au moins 12 caractères.");
      return;
    }
    if (newPwd !== confirmPwd) {
      setPwdError("Les deux mots de passe ne correspondent pas.");
      return;
    }
    if (!me?.email) {
      setPwdError("Email indisponible.");
      return;
    }

    setSavingPwd(true);
    const supabase = createClient();
    try {
      // Re-vérification du mot de passe actuel via signInWithPassword
      const { error: signinErr } = await supabase.auth.signInWithPassword({
        email: me.email,
        password: currentPwd,
      });
      if (signinErr) {
        setPwdError("Mot de passe actuel incorrect.");
        return;
      }

      const { error: upErr } = await supabase.auth.updateUser({ password: newPwd });
      if (upErr) {
        setPwdError(upErr.message);
        return;
      }

      toast.success("Mot de passe mis à jour");
      setCurrentPwd("");
      setNewPwd("");
      setConfirmPwd("");
    } catch (err) {
      setPwdError(err instanceof Error ? err.message : "Erreur");
    } finally {
      setSavingPwd(false);
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center py-16 text-[var(--muted)]">
        <Loader2 className="w-5 h-5 animate-spin" />
      </div>
    );
  }

  return (
    <div className="space-y-8 max-w-2xl">
      <header>
        <p className="text-xs uppercase tracking-wider text-[var(--muted)]">
          Mon compte
        </p>
        <h1 className="mt-1 text-2xl font-semibold tracking-tight">
          Paramètres personnels
        </h1>
        <p className="mt-1 text-sm text-[var(--muted)]">
          Gère ton profil, tes notifications et ton mot de passe.
        </p>
      </header>

      {/* Photo de profil */}
      <Section icon={Camera} title="Photo de profil">
        <div className="flex items-start gap-5">
          <Avatar
            user={{
              email: me?.email,
              name: me?.name,
              avatar_url: me?.avatar_url,
            }}
            size="xl"
          />
          <div className="flex-1 space-y-2">
            <p className="text-sm text-[var(--muted)] leading-relaxed">
              JPEG, PNG ou WebP. 5&nbsp;Mo maximum. La photo est visible par
              tous les membres avec qui tu partages un projet.
            </p>
            <div className="flex flex-wrap gap-2">
              <button
                type="button"
                onClick={() => fileInputRef.current?.click()}
                disabled={savingAvatar}
                className="inline-flex items-center gap-1.5 text-sm px-3 py-1.5 rounded-md bg-[var(--color-brand-red)] text-white disabled:opacity-50 hover:opacity-90"
              >
                {savingAvatar ? (
                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                ) : (
                  <Camera className="w-3.5 h-3.5" />
                )}
                {me?.avatar_url ? "Changer la photo" : "Choisir une photo"}
              </button>
              {me?.avatar_url && (
                <button
                  type="button"
                  onClick={removeAvatar}
                  disabled={savingAvatar}
                  className="inline-flex items-center gap-1.5 text-sm px-3 py-1.5 rounded-md text-[var(--muted)] hover:text-[var(--color-danger)] hover:bg-[var(--color-danger)]/10 disabled:opacity-50"
                >
                  <Trash2 className="w-3.5 h-3.5" />
                  Retirer
                </button>
              )}
            </div>
            <input
              ref={fileInputRef}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              className="hidden"
              onChange={(e) => {
                const f = e.target.files?.[0];
                if (f) uploadAvatar(f);
              }}
            />
          </div>
        </div>
      </Section>

      {/* Profil */}
      <Section icon={UserIcon} title="Profil">
        <form onSubmit={saveName} className="space-y-4">
          <Field label="Email" hint="L'email est immuable, lié à ton compte Supabase.">
            <div className="relative">
              <Mail className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-[var(--muted)]" />
              <input
                type="email"
                value={me?.email ?? ""}
                disabled
                className="w-full bg-transparent rounded-md border border-[var(--border)] pl-9 pr-3 py-2 text-sm font-mono text-[var(--muted)] cursor-not-allowed"
              />
            </div>
          </Field>

          <Field label="Nom affiché">
            <input
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              maxLength={120}
              placeholder="Jean Dupont"
              className="w-full bg-transparent rounded-md border border-[var(--border)] px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-neutral-400)]"
            />
          </Field>

          <div className="flex justify-end">
            <button
              type="submit"
              disabled={savingName || name.trim() === (me?.name ?? "")}
              className="text-sm px-3 py-1.5 rounded-md bg-[var(--color-brand-red)] text-white disabled:opacity-30 disabled:cursor-not-allowed hover:opacity-90"
            >
              {savingName ? "Enregistrement…" : "Enregistrer"}
            </button>
          </div>
        </form>
      </Section>

      {/* Notifications */}
      <Section icon={BellRing} title="Notifications email">
        <div className="space-y-5">
          <ToggleRow
            label="Tâches qui me sont attribuées"
            description="Reçois un email à chaque fois qu'un membre te confie une tâche, avec son titre, sa description, sa priorité et son échéance."
            value={notifyTask}
            disabled={savingNotif}
            onChange={(v) =>
              toggleNotif(
                "notify_task_assignment_email",
                v,
                setNotifyTask,
                setNotifyTask,
              )
            }
          />

          <div className="border-t border-[var(--border)]" />

          <ToggleRow
            label="Nouveaux documents sur mes projets"
            description="Reçois un email quand un membre ajoute un document (PDF, image, fichier) à un projet auquel tu participes."
            value={notifyDoc}
            disabled={savingNotif}
            onChange={(v) =>
              toggleNotif(
                "notify_project_document_email",
                v,
                setNotifyDoc,
                setNotifyDoc,
              )
            }
          />
        </div>
      </Section>

      {/* Mot de passe */}
      <Section icon={KeyRound} title="Mot de passe">
        <form onSubmit={changePassword} className="space-y-4">
          <Field label="Mot de passe actuel">
            <input
              type="password"
              autoComplete="current-password"
              required
              value={currentPwd}
              onChange={(e) => setCurrentPwd(e.target.value)}
              className="w-full bg-transparent rounded-md border border-[var(--border)] px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-neutral-400)]"
            />
          </Field>

          <Field
            label="Nouveau mot de passe"
            hint="Au moins 12 caractères."
          >
            <input
              type="password"
              autoComplete="new-password"
              required
              minLength={12}
              value={newPwd}
              onChange={(e) => setNewPwd(e.target.value)}
              className="w-full bg-transparent rounded-md border border-[var(--border)] px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-neutral-400)]"
            />
          </Field>

          <Field label="Confirmation">
            <input
              type="password"
              autoComplete="new-password"
              required
              minLength={12}
              value={confirmPwd}
              onChange={(e) => setConfirmPwd(e.target.value)}
              className="w-full bg-transparent rounded-md border border-[var(--border)] px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-neutral-400)]"
            />
          </Field>

          {pwdError && (
            <div className="rounded-md border border-[var(--color-danger)]/30 bg-[var(--color-danger)]/5 px-3 py-2 text-xs text-[var(--color-danger)]">
              {pwdError}
            </div>
          )}

          <div className="flex justify-end">
            <button
              type="submit"
              disabled={savingPwd || !currentPwd || !newPwd || !confirmPwd}
              className="text-sm px-3 py-1.5 rounded-md bg-[var(--color-brand-red)] text-white disabled:opacity-30 disabled:cursor-not-allowed hover:opacity-90"
            >
              {savingPwd ? "Mise à jour…" : "Changer le mot de passe"}
            </button>
          </div>
        </form>
      </Section>
    </div>
  );
}

function Section({
  icon: Icon,
  title,
  children,
}: {
  icon: typeof UserIcon;
  title: string;
  children: React.ReactNode;
}) {
  return (
    <section className="rounded-lg border border-[var(--border)] bg-[var(--surface)]">
      <header className="flex items-center gap-2 px-5 py-3 border-b border-[var(--border)]">
        <Icon size={14} strokeWidth={1.75} className="text-[var(--muted)]" />
        <h2 className="text-sm font-medium">{title}</h2>
      </header>
      <div className="p-5">{children}</div>
    </section>
  );
}

function Field({
  label,
  hint,
  children,
}: {
  label: string;
  hint?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-1.5">
      <label className="block text-xs font-medium">{label}</label>
      {children}
      {hint && <p className="text-[11px] text-[var(--muted)] mt-1">{hint}</p>}
    </div>
  );
}

function ToggleRow({
  label,
  description,
  value,
  onChange,
  disabled,
}: {
  label: string;
  description: string;
  value: boolean;
  onChange: (v: boolean) => void;
  disabled?: boolean;
}) {
  return (
    <div className="flex items-start gap-4">
      <div className="flex-1 min-w-0">
        <p className="text-sm font-medium">{label}</p>
        <p className="text-xs text-[var(--muted)] mt-1 leading-relaxed">
          {description}
        </p>
      </div>
      <button
        type="button"
        role="switch"
        aria-checked={value}
        disabled={disabled}
        onClick={() => onChange(!value)}
        className={`relative shrink-0 inline-flex h-6 w-11 items-center rounded-full transition-colors disabled:opacity-50 ${
          value
            ? "bg-[var(--color-brand-red)]"
            : "bg-[var(--color-neutral-300)] dark:bg-[var(--color-neutral-700)]"
        }`}
      >
        <span
          className={`inline-block size-4 rounded-full bg-white shadow transition-transform ${
            value ? "translate-x-6" : "translate-x-1"
          }`}
        />
      </button>
    </div>
  );
}
