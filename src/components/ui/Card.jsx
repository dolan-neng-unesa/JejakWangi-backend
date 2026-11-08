export function Card({ className = "", children, ...rest }) {
  return (
    <section className={`bg-white rounded-2xl border border-brand-200 ${className}`} {...rest}>
      {children}
    </section>
  );
}

export function CardTitle({ children, right, className = "" }) {
  return (
    <div className={`flex items-center justify-between gap-3 ${className}`}>
      <h2 className="text-base font-semibold text-brand-700 leading-snug">{children}</h2>
      {right}
    </div>
  );
}
