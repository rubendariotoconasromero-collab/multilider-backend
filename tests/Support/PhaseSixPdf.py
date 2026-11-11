import os
import re
import sys
from pathlib import Path

from pypdf import PdfReader


def report_text(path):
    return " ".join(" ".join(page.extract_text().split()) for page in PdfReader(path).pages)


def main():
    directory = Path(sys.argv[1]).resolve()
    root = Path(__file__).resolve().parents[2] / "storage" / "framework" / "testing"
    if os.environ.get("MULTILIDER_TEST_ISOLATED") != "1" or directory.parent != root.resolve():
        raise SystemExit("Isolated F6 evidence required.")
    if not re.fullmatch(r"f6-reports-[a-f0-9]{32}", directory.name):
        raise SystemExit("Unexpected F6 evidence directory.")
    reports = (
        "ventas-cobros",
        "cartera-mora",
        "comisiones",
        "desempeno-asesores",
        "inventario-propiedades",
    )
    for report in reports:
        download = report_text(directory / f"{report}.pdf")
        attachment = report_text(directory / f"{report}-mail.pdf")
        if not download or download != attachment:
            raise SystemExit(f"Different text in the download and attached PDF for {report}.")
    print(f"F6-A10: all {len(reports)} downloaded PDFs match their actual mail attachments.")


if __name__ == "__main__":
    main()
