let attendanceChart, classChart;

function initReport() {
  document
    .getElementById("generateBtn")
    .addEventListener("click", generateReport);
  document.getElementById("excelBtn").addEventListener("click", exportToExcel);
  document.getElementById("pdfBtn").addEventListener("click", exportToPDF);

  document.querySelectorAll(".tab").forEach((tab) => {
    tab.addEventListener("click", () => switchTab(tab.dataset.tab, tab));
  });

  generateReport();
}

// Event Listeners
document.addEventListener("DOMContentLoaded", () => {
  document
    .getElementById("generateBtn")
    .addEventListener("click", generateReport);
  document.getElementById("excelBtn").addEventListener("click", exportToExcel);
  document.getElementById("pdfBtn").addEventListener("click", exportToPDF);

  document.querySelectorAll(".tab").forEach((tab) => {
    tab.addEventListener("click", () => switchTab(tab.dataset.tab, tab));
  });

  generateReport(); // Initial load
});

function switchTab(tabName, tabElement) {
  document
    .querySelectorAll(".tab-content")
    .forEach((el) => el.classList.remove("active"));
  document
    .querySelectorAll(".tab")
    .forEach((el) => el.classList.remove("active"));
  document.getElementById(tabName).classList.add("active");
  tabElement.classList.add("active");
}

function generateReport() {
  const reportType = document.getElementById("reportType").value;
  const classVal = document.getElementById("classSelect").value;
  const subject = document.getElementById("subjectSelect").value;
  const startDate = document.getElementById("startDate").value;
  const endDate = document.getElementById("endDate").value;

  updateReportTitle(reportType);
  populateReportData(classVal, subject, startDate, endDate);
  updateAnalytics(classVal, subject);
}

function updateReportTitle(type) {
  const titles = {
    daily: "Daily Attendance Report",
    weekly: "Weekly Attendance Report",
    yearly: "Yearly Attendance Report",
  };
  document.getElementById("reportTitle").textContent =
    titles[type] || "Attendance Report";
}

function populateReportData(classVal, subject, startDate, endDate) {
  const sampleData = [
    {
      class: "10-A",
      subject: "Math",
      date: "2024-01-15",
      name: "Ahmed Ali",
      roll: "101",
      status: "present",
    },
    {
      class: "10-A",
      subject: "Math",
      date: "2024-01-15",
      name: "Fatima Khan",
      roll: "102",
      status: "absent",
    },
    {
      class: "10-A",
      subject: "Math",
      date: "2024-01-15",
      name: "Hassan Muhammad",
      roll: "103",
      status: "official",
    },
    {
      class: "10-B",
      subject: "Science",
      date: "2024-01-16",
      name: "Ali Raza",
      roll: "201",
      status: "present",
    },
    {
      class: "10-B",
      subject: "Science",
      date: "2024-01-16",
      name: "Sara Ahmed",
      roll: "202",
      status: "present",
    },
  ];

  const filteredData = sampleData.filter((row) => {
    const rowDate = new Date(row.date);
    const start = startDate ? new Date(startDate) : null;
    const end = endDate ? new Date(endDate) : null;
    return (
      (!classVal || row.class === classVal) &&
      (!subject || row.subject === subject) &&
      (!start || rowDate >= start) &&
      (!end || rowDate <= end)
    );
  });

  const tbody = document.getElementById("reportBody");
  tbody.innerHTML = filteredData
    .map(
      (row) => `
        <tr>
          <td>${row.class}</td>
          <td>${row.subject}</td>
          <td>${row.date}</td>
          <td>${row.name}</td>
          <td>${row.roll}</td>
          <td>${row.status === "present" ? "✓" : "-"}</td>
          <td>${row.status === "absent" ? "✓" : "-"}</td>
          <td>${row.status === "official" ? "✓" : "-"}</td>
          <td>-</td>
        </tr>
      `
    )
    .join("");
}

function updateAnalytics(classVal, subject) {
  const sampleData = [
    {
      roll: "101",
      name: "Ahmed Ali",
      present: 18,
      absent: 2,
      official: 1,
      class: "10-A",
      subject: "Math",
    },
    {
      roll: "102",
      name: "Fatima Khan",
      present: 15,
      absent: 4,
      official: 2,
      class: "10-A",
      subject: "Math",
    },
    {
      roll: "103",
      name: "Hassan Muhammad",
      present: 19,
      absent: 1,
      official: 1,
      class: "10-A",
      subject: "Math",
    },
    {
      roll: "201",
      name: "Ali Raza",
      present: 20,
      absent: 0,
      official: 1,
      class: "10-B",
      subject: "Science",
    },
    {
      roll: "202",
      name: "Sara Ahmed",
      present: 18,
      absent: 2,
      official: 0,
      class: "10-B",
      subject: "Science",
    },
  ];

  const filteredData = sampleData.filter(
    (s) =>
      (!classVal || s.class === classVal) && (!subject || s.subject === subject)
  );

  document.getElementById("totalStudents").textContent = filteredData.length;
  document.getElementById("totalPresent").textContent = filteredData.reduce(
    (sum, s) => sum + s.present,
    0
  );
  document.getElementById("totalAbsent").textContent = filteredData.reduce(
    (sum, s) => sum + s.absent,
    0
  );
  document.getElementById("totalOfficial").textContent = filteredData.reduce(
    (sum, s) => sum + s.official,
    0
  );

  const totalClasses = 21;
  const totalPresent = filteredData.reduce((sum, s) => sum + s.present, 0);
  const percentage = (
    (totalPresent / (filteredData.length * totalClasses)) * 100 || 0
  ).toFixed(1);
  document.getElementById("attendancePercent").textContent = percentage + "%";

  const tbody = document.getElementById("analyticsBody");
  tbody.innerHTML = filteredData
    .map((student) => {
      const total = student.present + student.absent + student.official;
      const studentPercent = ((student.present / total) * 100).toFixed(1);
      const status = studentPercent >= 75 ? "✓ Good" : "⚠ Low";
      return `<tr>
          <td>${student.roll}</td>
          <td>${student.name}</td>
          <td>${student.present}</td>
          <td>${student.absent}</td>
          <td>${student.official}</td>
          <td>${total}</td>
          <td>${studentPercent}%</td>
          <td>${status}</td>
        </tr>`;
    })
    .join("");

  drawCharts(filteredData);
}

function drawCharts(filteredData) {
  const ctx1 = document.getElementById("attendanceChart").getContext("2d");
  if (attendanceChart) attendanceChart.destroy();
  attendanceChart = new Chart(ctx1, {
    type: "line",
    data: {
      labels: ["Week 1", "Week 2", "Week 3", "Week 4"],
      datasets: [
        {
          label: "Attendance %",
          data: filteredData.map(() => Math.floor(Math.random() * 100)),
          borderColor: "#007bff",
          tension: 0.4,
          fill: true,
          backgroundColor: "rgba(0, 123, 255, 0.1)",
        },
      ],
    },
    options: { responsive: true, maintainAspectRatio: false },
  });

  const ctx2 = document.getElementById("classChart").getContext("2d");
  if (classChart) classChart.destroy();
  classChart = new Chart(ctx2, {
    type: "bar",
    data: {
      labels: filteredData.map((s) => s.class),
      datasets: [
        {
          label: "Present",
          data: filteredData.map((s) => s.present),
          backgroundColor: "#28a745",
        },
        {
          label: "Absent",
          data: filteredData.map((s) => s.absent),
          backgroundColor: "#dc3545",
        },
        {
          label: "Official",
          data: filteredData.map((s) => s.official),
          backgroundColor: "#ffc107",
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      indexAxis: "x",
    },
  });
}

function exportToExcel() {
  const container = document.querySelector(".ReportContainer");
  const table = container.querySelector("#reportTable");
  const ws = XLSX.utils.table_to_sheet(table);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, "Attendance Report");
  XLSX.writeFile(
    wb,
    `attendance_report_${new Date().toISOString().split("T")[0]}.xlsx`
  );
}

function exportToPDF() {
  const container = document.querySelector(".ReportContainer");
  const reportSection = container.querySelector("#report");
  const opt = {
    margin: 10,
    filename: `attendance_report_${new Date().toISOString().split("T")[0]}.pdf`,
    image: { type: "jpeg", quality: 0.98 },
    html2canvas: { scale: 2 },
    jsPDF: { orientation: "landscape", unit: "mm", format: "a4" },
  };
  html2pdf().set(opt).from(reportSection).save();
}
