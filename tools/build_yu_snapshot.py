#!/usr/bin/env python3
"""Builds the seeded Al Yamamah University institutional snapshot used by SAQF's
SeededInstitutionSource adapter. Source: public study-plan PDFs on yu.edu.sa
(fetched 2026-10-01). Row format:
  [code, title, credits, year, semester, group, R|E, prereqs, coreqs]
year/semester are None for elective-pool rows that can be taken in any slot.
"""
import json, os, re

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'data', 'yu')
os.makedirs(os.path.join(OUT, 'programs'), exist_ok=True)

institution = {
    "institution": {"code": "YU", "name": "Al Yamamah University", "name_ar": "جامعة اليمامة", "city": "Riyadh", "website": "https://yu.edu.sa/"},
    "snapshot": {"captured_on": "2026-10-01", "method": "Public study-plan PDFs on yu.edu.sa, transcribed into a structured snapshot", "status": "SEEDED — replace with Registrar/SIS feed in production"},
    "colleges": [
        {"code": "COE", "name": "College of Engineering", "name_ar": "كلية الهندسة"},
        {"code": "COB", "name": "College of Business", "name_ar": "كلية إدارة الأعمال"},
        {"code": "COL", "name": "College of Law", "name_ar": "كلية القانون"},
        {"code": "DAS", "name": "Deanship of Arts & Sciences", "name_ar": "عمادة الآداب والعلوم"},
    ],
    "departments": [
        {"code": "CED", "college": "COE", "name": "Computer Engineering Department"},
        {"code": "IED", "college": "COE", "name": "Industrial Engineering Department"},
        {"code": "ARD", "college": "COE", "name": "Architecture Department"},
        {"code": "MNS", "college": "COE", "name": "Department of Mathematics & Natural Sciences"},
        {"code": "AFD", "college": "COB", "name": "Accounting & Finance Department"},
        {"code": "MMD", "college": "COB", "name": "Management and Marketing Department"},
        {"code": "MISD", "college": "COB", "name": "Management Information Systems Department"},
        {"code": "LAWD", "college": "COL", "name": "Law Department"},
        {"code": "HUM", "college": "COL", "name": "Humanities Department"},
        {"code": "ASU", "college": "DAS", "name": "Arts & Sciences Unit"},
    ],
    # Course ownership: which department is the authoritative owner of a course prefix.
    # Used to resolve conflicts between program plans that list the same course.
    "ownership": [
        {"prefix": "CIS", "department": "CED"}, {"prefix": "SWE", "department": "CED"},
        {"prefix": "NES", "department": "CED"}, {"prefix": "CNE", "department": "CED"},
        {"prefix": "CYB", "department": "CED"}, {"prefix": "PRQ", "department": "CED"},
        {"prefix": "IEG", "department": "IED"}, {"prefix": "ENR", "department": "IED"}, {"prefix": "MEG", "department": "IED"},
        {"prefix": "ARCH", "department": "ARD"}, {"prefix": "MEC", "department": "ARD"},
        {"prefix": "MTH 1", "department": "MNS"}, {"prefix": "MTH 2", "department": "MNS"}, {"prefix": "MTH 3", "department": "MNS"},
        {"prefix": "MTH 100", "department": "MMD"}, {"prefix": "MTH 110", "department": "MMD"},
        {"prefix": "PHY", "department": "MNS"}, {"prefix": "CHM", "department": "MNS"}, {"prefix": "STT 103", "department": "MNS"},
        {"prefix": "ACC", "department": "AFD"}, {"prefix": "FIN", "department": "AFD"}, {"prefix": "PGRD 495", "department": "AFD"},
        {"prefix": "MGT", "department": "MMD"}, {"prefix": "MKT", "department": "MMD"}, {"prefix": "ECO", "department": "MMD"},
        {"prefix": "STT", "department": "MMD"}, {"prefix": "BUS", "department": "MMD"}, {"prefix": "HRM", "department": "MMD"},
        {"prefix": "ENT", "department": "MMD"}, {"prefix": "PMT", "department": "MMD"}, {"prefix": "PGRD", "department": "MMD"},
        {"prefix": "MIS", "department": "MISD"},
        {"prefix": "LAW", "department": "LAWD"},
        {"prefix": "ISL", "department": "HUM"}, {"prefix": "ARB", "department": "HUM"}, {"prefix": "ENG", "department": "HUM"},
        {"prefix": "SOS", "department": "HUM"}, {"prefix": "PHL", "department": "HUM"}, {"prefix": "PSY", "department": "HUM"},
        {"prefix": "FRE", "department": "HUM"}, {"prefix": "CHI", "department": "HUM"},
        {"prefix": "CSK", "department": "ASU"},
    ],
}

CAC_SOS = [
    ("SO1", "Skills", "Analyze a complex computing problem and apply principles of computing and other relevant disciplines to identify solutions."),
    ("SO2", "Skills", "Design, implement, and evaluate a computing-based solution to meet a given set of computing requirements in the context of the program's discipline."),
    ("SO3", "Skills", "Communicate effectively in a variety of professional contexts."),
    ("SO4", "Values, Autonomy, and Responsibility", "Recognize professional responsibilities and make informed judgments in computing practice based on legal and ethical principles."),
    ("SO5", "Values, Autonomy, and Responsibility", "Function effectively as a member or leader of a team engaged in activities appropriate to the program's discipline."),
]
EAC_SOS = [
    ("SO1", "Knowledge and Understanding", "Identify, formulate, and solve complex engineering problems by applying principles of engineering, science, and mathematics."),
    ("SO2", "Skills", "Apply engineering design to produce solutions that meet specified needs with consideration of public health, safety, and welfare, as well as global, cultural, social, environmental, and economic factors."),
    ("SO3", "Skills", "Communicate effectively with a range of audiences."),
    ("SO4", "Values, Autonomy, and Responsibility", "Recognize ethical and professional responsibilities in engineering situations and make informed judgments that consider the impact of engineering solutions in global, economic, environmental, and societal contexts."),
    ("SO5", "Values, Autonomy, and Responsibility", "Function effectively on a team whose members together provide leadership, create a collaborative environment, establish goals, plan tasks, and meet objectives."),
    ("SO6", "Skills", "Develop and conduct appropriate experimentation, analyze and interpret data, and use engineering judgment to draw conclusions."),
    ("SO7", "Values, Autonomy, and Responsibility", "Acquire and apply new knowledge as needed, using appropriate learning strategies."),
]

GENED_HSS = [
    ["SOS 102", "Introduction to Social Science", 3, None, None, "Humanities / Social Science Electives", "E", "ORN 04R;ORN 04C", ""],
    ["PHL 101", "Critical Thinking", 3, None, None, "Humanities / Social Science Electives", "E", "ORN 04R;ORN 04C", ""],
    ["PSY 101", "Principle of Psychology", 3, None, None, "Humanities / Social Science Electives", "E", "ORN 04R;ORN 04C", ""],
    ["SOS 101", "Saudi Heritage", 3, None, None, "Humanities / Social Science Electives", "E", "ORN 04R;ORN 04C", ""],
    ["FRE 106", "Conversational French 1", 3, None, None, "Humanities / Social Science Electives", "E", "ORN 04R;ORN 04C", ""],
    ["CHI 107", "Chinese Language", 3, None, None, "Humanities / Social Science Electives", "E", "ORN 04R;ORN 04C", ""],
    ["ENG 103", "Introduction to English Literature", 3, None, None, "Humanities / Social Science Electives", "E", "ORN 05R;ORN 05C", ""],
]

def slot(title, credits, year, sem, group):
    """An elective slot in the semester grid (not a catalog course)."""
    return ["SLOT", title, credits, year, sem, group, "E", "", ""]

programs = []

# ---------------------------------------------------------------- SWE
programs.append({
    "code": "SWE", "name": "Bachelor of Science in Software Engineering", "short_name": "Software Engineering",
    "degree": "BSc", "level": "Undergraduate", "department": "CED", "total_credits": 142,
    "plan_version": "V9.8", "plan_date": "2026-08-05",
    "source_url": "https://yu.edu.sa/wp-content/uploads/2026/08/SP-Software-Engineering-Study-Plan-V9.8-05Aug2026.pdf",
    "accreditation_note": "Program page states ABET (CAC) accreditation.",
    "plos": CAC_SOS,
    "plo_source": "ABET CAC general student outcomes, as published by YU for its computing programs. Confirm SWE-approved PLO statements on integration.",
    "elective_rules": [{"group": "SWE Electives", "courses": 3, "credits": 9}, {"group": "Humanities / Social Science Electives", "courses": 2, "credits": 6}],
    "courses": [
        ["CIS 103", "Programming Fundamentals I", 4, 1, 1, "Major Requirements", "R", "ORN 03C;ORN 03R", ""],
        ["MTH 106", "Discrete Mathematics", 3, 1, 1, "Core Mathematics", "R", "ORN 04C;ORN 04R", ""],
        ["CHM 101", "General Chemistry", 4, 1, 1, "Core Science", "R", "ORN 04R;ORN 04C", ""],
        ["ENG 101", "English Essay Writing", 3, 1, 1, "General Education", "R", "ORN 05R;ORN 05C", ""],
        ["ISL 101", "Foundation of Islamic Culture", 2, 1, 1, "General Education", "R", "ORN 02R;ORN 02C", ""],
        ["ARB 102", "Communication Skills in Arabic", 2, 1, 1, "General Education", "R", "ORN 02R;ORN 02C", ""],
        slot("Humanities / Social Science Elective I", 3, 1, 1, "Humanities / Social Science Electives"),
        ["CIS 104", "Programming Fundamentals II", 4, 1, 2, "Major Requirements", "R", "CIS 103", ""],
        ["MTH 104", "Calculus I", 3, 1, 2, "Core Mathematics", "R", "ORN 04R;ORN 04C", ""],
        ["PHY 103", "Physics I", 4, 1, 2, "Core Science", "R", "ORN 04R;ORN 04C", ""],
        ["STT 103", "Probability and Statistics", 3, 1, 2, "General Education", "R", "ORN 04C;ORN 04R", ""],
        ["ENG 201", "Technical Report Writing", 3, 1, 2, "General Education", "R", "ENG 101", ""],
        slot("Humanities / Social Science Elective II", 3, 1, 2, "Humanities / Social Science Electives"),
        ["CIS 201", "Fundamentals of Web Design", 3, 2, 1, "Major Requirements", "R", "CIS 103", ""],
        ["CIS 202", "Data Structures", 3, 2, 1, "Major Requirements", "R", "CIS 104", ""],
        ["MIS 201", "Introduction to Information Systems", 3, 2, 1, "Major Requirements", "R", "ORN 05C;ORN 05R", ""],
        ["SWE 202", "Introduction to Software Engineering", 3, 2, 1, "Software Engineering", "R", "CIS 104", ""],
        ["MTH 204", "Calculus II", 3, 2, 1, "Core Mathematics", "R", "MTH 104", ""],
        ["PHY 203", "Physics II", 4, 2, 1, "Core Science", "R", "PHY 103", ""],
        ["CIS 221", "Introduction to Database Systems", 3, 2, 2, "Major Requirements", "R", "CIS 104", ""],
        ["NES 212", "Data Communications & Computer Networks", 3, 2, 2, "Major Requirements", "R", "CIS 201", ""],
        ["MTH 304", "Differential Equations", 3, 2, 2, "Core Mathematics", "R", "MTH 204", ""],
        ["ISL 202", "Financial Awareness", 2, 2, 2, "General Education", "R", "ORN 02R;ORN 02C", ""],
        ["ARB 202", "Writing Skills in Arabic", 2, 2, 2, "General Education", "R", "ORN 02R;ORN 02C", ""],
        ["CIS 304", "Computer Architecture", 3, 3, 1, "Major Requirements", "R", "CIS 202", ""],
        ["CIS 383", "Cyber Security and Cryptography", 3, 3, 1, "Major Requirements", "R", "NES 212", ""],
        ["CIS 386", "Project Management", 3, 3, 1, "Software Engineering", "R", "SWE 202", ""],
        ["SWE 300", "Software Process & Modelling", 3, 3, 1, "Software Engineering", "R", "SWE 202", ""],
        ["SWE 301", "Software Requirements Engineering", 3, 3, 1, "Software Engineering", "R", "SWE 202", ""],
        ["MTH 301", "Linear Algebra", 3, 3, 1, "Core Mathematics", "R", "MTH 104", ""],
        ["CIS 321", "Operating Systems", 3, 3, 2, "Major Requirements", "R", "CIS 304", ""],
        ["CIS 316", "Introduction to Artificial Intelligence", 3, 3, 2, "Major Requirements", "R", "SWE 202", ""],
        ["CIS 381", "Computer Ethics", 2, 3, 2, "Major Requirements", "R", "SWE 202", ""],
        ["SWE 302", "Software Architecture & Design", 3, 3, 2, "Software Engineering", "R", "SWE 202", ""],
        ["SWE 312", "Software Construction & User Interface", 3, 3, 2, "Software Engineering", "R", "SWE 202", ""],
        ["SWE 322", "Advanced Web Programming", 3, 3, 2, "Software Engineering", "R", "CIS 201;CIS 221", ""],
        ["CIS 491", "Graduation Project I", 3, 4, 1, "Software Engineering", "R", "90 CH;SWE 300;SWE 301;SWE 302", ""],
        ["CIS 443", "Cloud Computing", 3, 4, 1, "Major Requirements", "R", "NES 212", ""],
        ["SWE 321", "Advanced User Interface Design", 3, 4, 1, "Software Engineering", "R", "SWE 301", ""],
        ["SWE 411", "Software Verification & Validation", 3, 4, 1, "Software Engineering", "R", "SWE 312", ""],
        slot("SWE Elective I", 3, 4, 1, "SWE Electives"),
        ["CIS 492", "Graduation Project II", 3, 4, 2, "Major Requirements", "R", "CIS 491", ""],
        ["SWE 401", "Software Quality Assurance", 3, 4, 2, "Software Engineering", "R", "SWE 301", ""],
        slot("SWE Elective II", 3, 4, 2, "SWE Electives"),
        slot("SWE Elective III", 3, 4, 2, "SWE Electives"),
        ["CSK 001", "Career Skills", 0, 4, 2, "Software Engineering", "R", "", ""],
        ["SWE 410", "Exit Exam Preparation", 1, 4, 2, "Software Engineering", "R", "90 CH;SWE 300;SWE 301;SWE 302", ""],
        ["CIS 490", "Cooperative Assignment", 6, 4, 3, "Major Requirements", "R", "90 CH;SWE 410", ""],
        ["SWE 402", "Software Maintenance and Evolution", 3, None, None, "SWE Electives", "E", "SWE 312", ""],
        ["SWE 412", "Mobile Application Development", 3, None, None, "SWE Electives", "E", "SWE 312", ""],
        ["SWE 413", "Design Patterns", 3, None, None, "SWE Electives", "E", "SWE 302", ""],
        ["SWE 414", "Formal Methods in Software Engineering", 3, None, None, "SWE Electives", "E", "SWE 202", ""],
        ["SWE 415", "Software Usability Engineering", 3, None, None, "SWE Electives", "E", "SWE 301", ""],
        ["SWE 421", "Game Development", 3, None, None, "SWE Electives", "E", "SWE 312", ""],
        ["SWE 429", "Selected Topics in Software Engineering", 3, None, None, "SWE Electives", "E", "SWE 312", ""],
        ["CIS 222", "Interactive Media", 3, None, None, "SWE Electives", "E", "CIS 201", ""],
        ["CIS 416", "Introduction to Machine Learning", 3, None, None, "SWE Electives", "E", "MTH 301;STT 103;CIS 316", ""],
        ["NES 424", "IoT Architectures, Protocols and Security", 3, None, None, "SWE Electives", "E", "NES 212", ""],
        ["NES 481", "Security Policies and Procedures", 3, None, None, "SWE Electives", "E", "NES 212", ""],
        ["MIS 432", "Enterprise Systems", 3, None, None, "SWE Electives", "E", "CIS 221", ""],
    ] + GENED_HSS,
})

# ---------------------------------------------------------------- CNE
programs.append({
    "code": "CNE", "name": "Bachelor of Science in Computer Network Engineering", "short_name": "Computer Network Engineering",
    "degree": "BSc", "level": "Undergraduate", "department": "CED", "total_credits": 141,
    "plan_version": "V1.0", "plan_date": "2025-08-16",
    "source_url": "https://yu.edu.sa/wp-content/uploads/2025/08/SP-Network-Engineering-Study-Plan-V1.0-16-August-2025.pdf",
    "accreditation_note": "Program page states ABET (EAC) accreditation.",
    "plos": EAC_SOS,
    "plo_source": "ABET EAC general student outcomes (public criteria). Placeholder until CNE-approved PLOs are synced.",
    "elective_rules": [{"group": "CNE Electives", "courses": 3, "credits": 9}, {"group": "Humanities / Social Science Electives", "courses": 2, "credits": 6}],
    "courses": [
        ["CIS 103", "Programming Fundamentals I", 4, 1, 1, "Core", "R", "ORN 03C;ORN 03R", ""],
        ["CHM 101", "General Chemistry", 4, 1, 1, "Core", "R", "ORN 04R;ORN 04C", ""],
        ["MTH 106", "Discrete Mathematics", 3, 1, 1, "Core", "R", "ORN 04C;ORN 04R", ""],
        ["MTH 104", "Calculus I", 3, 1, 1, "Core", "R", "ORN 03C;ORN 03R", ""],
        ["ENG 101", "English Essay Writing", 3, 1, 1, "Core", "R", "ORN 05R;ORN 05C", ""],
        ["PHY 103", "Physics I", 4, 1, 1, "Core", "R", "ORN 04R;ORN 04C", ""],
        ["ISL 101", "Foundation of Islamic Culture", 2, 1, 1, "Core", "R", "ORN 02R;ORN 02C", ""],
        slot("Humanities / Social Science Elective I", 3, 1, 1, "Humanities / Social Science Electives"),
        slot("Humanities / Social Science Elective II", 3, 1, 2, "Humanities / Social Science Electives"),
        ["CIS 104", "Programming Fundamentals II", 4, 1, 2, "Core", "R", "CIS 103", ""],
        ["CNE 100", "Data Communications and Computer Networks", 3, 1, 2, "Core", "R", "CIS 103", ""],
        ["ARB 102", "Communication Skills in Arabic", 2, 1, 2, "Core", "R", "ORN 02R;ORN 02C", ""],
        ["CIS 202", "Data Structures", 3, 2, 1, "Core", "R", "CIS 104", ""],
        ["CNE 200", "Network Protocols & Architecture", 3, 2, 1, "Core", "R", "CNE 100", ""],
        ["MTH 204", "Calculus II", 3, 2, 1, "Core", "R", "MTH 104", ""],
        ["PHY 203", "Physics II", 4, 2, 1, "Core", "R", "PHY 103", ""],
        ["ENG 201", "Technical Report Writing", 3, 2, 1, "Core", "R", "ENG 101", ""],
        ["STT 103", "Probability and Statistics", 3, 2, 1, "Core", "R", "ORN 03C;ORN 03R", ""],
        ["ISL 202", "Financial Awareness", 2, 2, 1, "Core", "R", "ORN 02R;ORN 02C", ""],
        ["CIS 221", "Introduction to Database Systems", 3, 2, 2, "Core", "R", "CIS 104", ""],
        ["CNE 300", "Switching and Routing", 3, 2, 2, "Core", "R", "CNE 200", ""],
        ["CNE 221", "Digital Logic and Design", 3, 2, 2, "Core", "R", "MTH 106", ""],
        ["MTH 301", "Linear Algebra", 3, 2, 2, "Core", "R", "MTH 104", ""],
        ["ARB 202", "Writing Skills in Arabic", 2, 2, 2, "Core", "R", "ORN 02R;ORN 02C", ""],
        ["CIS 304", "Computer Architecture", 3, 3, 1, "Core", "R", "CNE 221", ""],
        ["CIS 383", "Cyber Security and Cryptography", 3, 3, 1, "Core", "R", "CNE 100", ""],
        ["CIS 386", "Project Management", 3, 3, 1, "Core", "R", "CIS 221", ""],
        ["CNE 303", "Wireless Networks", 3, 3, 1, "Core", "R", "CNE 200", ""],
        ["CNE 305", "Internet of Things", 3, 3, 1, "Core", "R", "CNE 200", ""],
        ["MTH 304", "Differential Equations", 3, 3, 1, "Core", "R", "MTH 204", ""],
        slot("CNE Elective I", 3, 3, 2, "CNE Electives"),
        ["CIS 306", "Embedded Systems", 3, 3, 2, "Core", "R", "CIS 304", ""],
        ["CIS 321", "Operating Systems", 3, 3, 2, "Core", "R", "CIS 304", ""],
        ["CNE 307", "Network Security", 3, 3, 2, "Core", "R", "CIS 383;CNE 200", ""],
        ["CNE 308", "Network Design and Modelling", 3, 3, 2, "Core", "R", "CNE 300", ""],
        ["CNE 310", "Network Programming", 3, 3, 2, "Core", "R", "CNE 200", ""],
        slot("CNE Elective II", 3, 4, 1, "CNE Electives"),
        ["CNE 405", "Cloud Infrastructure Design", 2, 4, 1, "Core", "R", "CNE 300", ""],
        ["CNE 406", "Computer Network Management", 3, 4, 1, "Core", "R", "CNE 300", ""],
        ["CIS 416", "Introduction to Machine Learning", 3, 4, 1, "Core", "R", "MTH 301", ""],
        ["CIS 491", "Graduation Project I", 3, 4, 1, "Core", "R", "CNE 308;CIS 386;90 CH", ""],
        ["CSK 001", "Career Skills", 0, 4, 1, "Core", "R", "", ""],
        slot("CNE Elective III", 3, 4, 2, "CNE Electives"),
        ["CNE 411", "Datacenters and System Administration", 3, 4, 2, "Core", "R", "CIS 321;CNE 300", ""],
        ["CNE 412", "Virtual Network Design and Implementation", 3, 4, 2, "Core", "R", "CNE 300", ""],
        ["CIS 492", "Graduation Project II", 3, 4, 2, "Core", "R", "CIS 491", ""],
        ["CIS 490", "Cooperative Assignment", 6, 4, 3, "Core", "R", "CNE 308;90 CH", ""],
        ["CNE 450", "Signals and System Analysis", 3, None, None, "CNE Electives", "E", "MTH 304", ""],
        ["CNE 451", "Network Troubleshooting", 3, None, None, "CNE Electives", "E", "CNE 300", ""],
        ["CNE 452", "Network Simulation and Modeling", 3, None, None, "CNE Electives", "E", "CNE 300", ""],
        ["CNE 453", "Advanced Topics of Switching and Routing", 3, None, None, "CNE Electives", "E", "CNE 300", ""],
        ["CNE 454", "Enterprise Networking", 3, None, None, "CNE Electives", "E", "CNE 300", ""],
        ["CNE 455", "IT Technical Support", 3, None, None, "CNE Electives", "E", "CNE 300", ""],
        ["CNE 456", "Optical Networks", 3, None, None, "CNE Electives", "E", "CNE 300", ""],
        ["CNE 457", "Broadband Networks", 3, None, None, "CNE Electives", "E", "CNE 300", ""],
        ["CNE 458", "Fault-Tolerant Systems", 3, None, None, "CNE Electives", "E", "CNE 300", ""],
        ["CNE 470", "Selected Topics in Computer Network Engineering", 3, None, None, "CNE Electives", "E", "CNE 300", ""],
        ["CNE 471", "Smart Cities and IoT Applications", 3, None, None, "CNE Electives", "E", "CNE 305", ""],
        ["CNE 472", "Mobile Edge Computing (MEC)", 3, None, None, "CNE Electives", "E", "CNE 303", ""],
        ["CNE 473", "5G Technologies and Beyond", 3, None, None, "CNE Electives", "E", "CNE 303", ""],
        ["CNE 474", "Mobile Network Architecture and Protocols", 3, None, None, "CNE Electives", "E", "CNE 303", ""],
        ["CNE 475", "Virtual Networking and Software Defined Networking", 3, None, None, "CNE Electives", "E", "CNE 303", ""],
        ["CNE 480", "Cyber Threats", 3, None, None, "CNE Electives", "E", "CIS 383", ""],
        ["CNE 481", "Ethical Hacking", 3, None, None, "CNE Electives", "E", "CIS 383;CNE 300", ""],
        ["CNE 482", "Digital Forensics and Incident Response", 3, None, None, "CNE Electives", "E", "CIS 383", ""],
        ["CNE 483", "Cloud Services and Security", 3, None, None, "CNE Electives", "E", "CIS 383", ""],
        ["CNE 484", "Critical Infrastructure Protection", 3, None, None, "CNE Electives", "E", "CNE 307", ""],
        ["CNE 485", "Intrusion Detection/Prevention Systems", 3, None, None, "CNE Electives", "E", "CNE 307", ""],
    ] + GENED_HSS,
})

# ---------------------------------------------------------------- IE
programs.append({
    "code": "IE", "name": "Bachelor of Science in Industrial Engineering", "short_name": "Industrial Engineering",
    "degree": "BSc", "level": "Undergraduate", "department": "IED", "total_credits": 134,
    "plan_version": "V2.0", "plan_date": "2026-07-01",
    "source_url": "https://yu.edu.sa/wp-content/uploads/2026/08/SP-Industrial-Engineering_Y.2026-July-2026-V-2.0-134-CR.pdf",
    "accreditation_note": "Program page states ABET (EAC) accreditation.",
    "plos": EAC_SOS,
    "plo_source": "ABET EAC general student outcomes (public criteria). Placeholder until IE-approved PLOs are synced.",
    "elective_rules": [{"group": "IE Electives", "courses": 2, "credits": 6}, {"group": "Humanities / Social Science Electives", "courses": 2, "credits": 6}],
    "courses": [
        ["CIS 103", "Programming Fundamentals I", 4, 1, 1, "First Year", "R", "ORN 04R;ORN 04C", ""],
        ["MTH 104", "Calculus I", 3, 1, 1, "First Year", "R", "ORN 04R;ORN 04C", ""],
        ["STT 103", "Probability and Statistics", 3, 1, 1, "First Year", "R", "ORN 04R;ORN 04C", ""],
        ["CHM 101", "General Chemistry", 4, 1, 1, "First Year", "R", "ORN 04R;ORN 04C", ""],
        ["ARB 102", "Communication Skills in Arabic", 2, 1, 1, "First Year", "R", "ORN 02R;ORN 02C", ""],
        slot("Humanities / Social Science Elective I", 3, 1, 1, "Humanities / Social Science Electives"),
        ["ENG 101", "English Essay Writing", 3, 1, 2, "First Year", "R", "ORN 05R;ORN 05C", ""],
        ["MTH 106", "Discrete Mathematics", 3, 1, 2, "First Year", "R", "ORN 04R;ORN 04C", ""],
        ["PHY 103", "Physics I", 4, 1, 2, "First Year", "R", "ORN 04R;ORN 04C", ""],
        ["ENR 201", "Engineering Drawing and CAD", 3, 1, 2, "First Year", "R", "ORN 04R;ORN 04C", ""],
        ["ISL 101", "Foundation of Islamic Culture", 2, 1, 2, "First Year", "R", "ORN 02R;ORN 02C", ""],
        slot("Humanities / Social Science Elective II", 3, 1, 2, "Humanities / Social Science Electives"),
        ["MTH 204", "Calculus II", 3, 2, 1, "Second Year", "R", "MTH 104", ""],
        ["MTH 301", "Linear Algebra", 3, 2, 1, "Second Year", "R", "MTH 104", ""],
        ["IEG 201", "Introduction to Engineering Design", 2, 2, 1, "Second Year", "R", "ENR 201", ""],
        ["MEG 211", "Fundamentals of Materials Engineering", 3, 2, 1, "Second Year", "R", "PHY 103;CHM 101", ""],
        ["IEG 301", "Design of Experiments", 3, 2, 1, "Second Year", "R", "STT 103", ""],
        ["ISL 202", "Financial Awareness", 2, 2, 1, "Second Year", "R", "ORN 02R;ORN 02C", ""],
        ["ARB 202", "Writing Skills in Arabic", 2, 2, 1, "Second Year", "R", "ORN 02R;ORN 02C", ""],
        ["PHY 203", "Physics II", 4, 2, 2, "Second Year", "R", "PHY 103", ""],
        ["IEG 202", "Social and Ethical Aspects in Engineering", 2, 2, 2, "Second Year", "R", "IEG 201", ""],
        ["IEG 321", "Operations Research I", 3, 2, 2, "Second Year", "R", "MTH 301;CIS 103", ""],
        ["IEG 303", "Quality Control", 3, 2, 2, "Second Year", "R", "IEG 301", ""],
        ["IEG 341", "Manufacturing Processes I", 3, 2, 2, "Second Year", "R", "MEG 211", "IEG 332"],
        ["IEG 332", "Work Design and Analysis", 3, 2, 2, "Second Year", "R", "", ""],
        ["MTH 304", "Differential Equations", 3, 3, 1, "Third Year", "R", "MTH 204", ""],
        ["IEG 304", "Engineering Economy and Costing", 3, 3, 1, "Third Year", "R", "IEG 201", ""],
        ["IEG 311", "Production and Inventory Systems", 3, 3, 1, "Third Year", "R", "IEG 301", ""],
        ["IEG 323", "Systems Simulation", 3, 3, 1, "Third Year", "R", "IEG 321", ""],
        ["IEG 400", "Product Development and Innovation", 3, 3, 1, "Third Year", "R", "IEG 201;IEG 303", ""],
        ["IEG 312", "Operations Management", 3, 3, 2, "Third Year", "R", "IEG 311;IEG 304", ""],
        ["IEG 322", "Operations Research II", 3, 3, 2, "Third Year", "R", "IEG 321;MTH 304", ""],
        ["IEG 342", "Manufacturing Processes II", 3, 3, 2, "Third Year", "R", "IEG 341;IEG 400", ""],
        ["IEG 431", "Ergonomics", 3, 3, 2, "Third Year", "R", "IEG 332", ""],
        ["IEG 450", "Industrial Facility Design and Material Handling", 3, 3, 2, "Third Year", "R", "IEG 332", ""],
        ["IEG 302", "Engineering Reliability", 2, 4, 1, "Fourth Year", "R", "IEG 312", ""],
        ["IEG 345", "Industrial Control Systems and Automation", 3, 4, 1, "Fourth Year", "R", "PHY 203;MTH 304;IEG 342", ""],
        ["IEG 411", "Project Management", 3, 4, 1, "Fourth Year", "R", "IEG 312", ""],
        ["IEG 430", "Safety Engineering", 3, 4, 1, "Fourth Year", "R", "IEG 431", ""],
        ["IEG 410", "Exit Exam Preparation", 1, 4, 1, "Fourth Year", "R", "", "IEG 411"],
        ["IEG 490", "Graduation Design Project I", 2, 4, 1, "Fourth Year", "R", "85 CH;IEG 431;IEG 450", ""],
        ["IEG 351", "Manufacturing Systems", 3, 4, 2, "Fourth Year", "R", "IEG 302;IEG 342", ""],
        slot("IE Elective I", 3, 4, 2, "IE Electives"),
        slot("IE Elective II", 3, 4, 2, "IE Electives"),
        ["CSK 001", "Career Skills", 0, 4, 2, "Fourth Year", "R", "", ""],
        ["IEG 491", "Graduation Design Project II", 2, 4, 2, "Fourth Year", "R", "IEG 490", ""],
        ["IEG 497", "Co-op Practical Training", 6, 4, 3, "Co-op", "R", "90 CH", ""],
        ["IEG 403", "Six Sigma and Lean Operations", 3, None, None, "IE Electives", "E", "IEG 303;100 CH", ""],
        ["IEG 413", "Supply Chain", 3, None, None, "IE Electives", "E", "IEG 312;100 CH", ""],
        ["IEG 414", "Production System Operations", 3, None, None, "IE Electives", "E", "IEG 312;100 CH", ""],
        ["IEG 415", "Scheduling of Industrial Operations", 3, None, None, "IE Electives", "E", "IEG 322;IEG 411;100 CH", ""],
        ["IEG 446", "Direct Digital Manufacturing", 3, None, None, "IE Electives", "E", "IEG 342;100 CH", ""],
        ["IEG 447", "Computer Integrated Manufacturing", 3, None, None, "IE Electives", "E", "IEG 345;100 CH", ""],
        ["MIS 327", "Database Management and Design", 3, None, None, "IE Electives", "E", "CIS 103;100 CH", ""],
        ["IEG 404", "Data Science for Engineers", 3, None, None, "IE Electives", "E", "CIS 103;IEG 301;100 CH", ""],
    ] + GENED_HSS,
})

# ---------------------------------------------------------------- ARCH
programs.append({
    "code": "ARCH", "name": "Bachelor of Architecture", "short_name": "Architecture",
    "degree": "BArch", "level": "Undergraduate", "department": "ARD", "total_credits": None,
    "plan_version": "Study plan starting 2023", "plan_date": "2023-09-01",
    "source_url": "https://yu.edu.sa/wp-content/uploads/2024/11/Architecture_StudyPlan_2023.pdf",
    "accreditation_note": "",
    "plos": [],
    "plo_source": "No PLO statements found on the public program page.",
    "elective_rules": [],
    "courses": [
        ["ARCH 101", "Basic Design Studio I", 3, 1, 1, "Major Requirements", "R", "ORN 02C;ORN 02R", ""],
        ["ARCH 102", "Architectural Drawing I", 3, 1, 1, "Major Requirements", "R", "ORN 02C;ORN 02R", ""],
        ["ARCH 111", "Basic Design Studio II", 3, 1, 2, "Major Requirements", "R", "ARCH 101;ARCH 102", ""],
        ["ARCH 112", "Architectural Drawing II", 3, 1, 2, "Major Requirements", "R", "ARCH 101;ARCH 102", ""],
        ["ARCH 113", "Computer-Aided Design I", 2, 1, 2, "Major Requirements", "R", "ARCH 102", ""],
        ["ARCH 201", "Architectural Design I", 5, 2, 1, "Major Requirements", "R", "ARCH 111;ORN 05C;ORN 05R", ""],
        ["ARCH 202", "History of Architecture", 3, 2, 1, "Major Requirements", "R", "ARCH 111;ORN 05C;ORN 05R", ""],
        ["ARCH 203", "Computer-Aided Design II", 2, 2, 1, "Major Requirements", "R", "ARCH 113", ""],
        ["ARCH 204", "Building Construction I", 3, 2, 1, "Major Requirements", "R", "ARCH 112", ""],
        ["ARCH 211", "Architectural Design II", 5, 2, 2, "Major Requirements", "R", "ARCH 201", ""],
        ["ARCH 212", "Theory of Architecture", 3, 2, 2, "Major Requirements", "R", "ARCH 202", ""],
        ["ARCH 213", "Introduction to Environmental Control", 3, 2, 2, "Major Requirements", "R", "ARCH 201", ""],
        ["ARCH 214", "Building Materials and Components", 2, 2, 2, "Major Requirements", "R", "ARCH 204", ""],
        ["ARCH 301", "Architectural Design III", 5, 3, 1, "Major Requirements", "R", "ARCH 211", ""],
        ["ARCH 302", "Building Construction II", 3, 3, 1, "Major Requirements", "R", "ARCH 214", ""],
        ["ARCH 303", "Landscape and Site Planning", 3, 3, 1, "Major Requirements", "R", "ARCH 211", ""],
        ["ARCH 304", "Structure I", 3, 3, 1, "Major Requirements", "R", "ARCH 211;MEC 103", ""],
        ["ARCH 311", "Architectural Design IV", 5, 3, 2, "Major Requirements", "R", "ARCH 301", ""],
        ["ARCH 312", "Urban Planning & Design", 3, 3, 2, "Major Requirements", "R", "ARCH 303", ""],
        ["ARCH 313", "Contemporary Issues in Architecture", 2, 3, 2, "Major Requirements", "R", "ARCH 212", ""],
        ["ARCH 314", "Structure II", 3, 3, 2, "Major Requirements", "R", "ARCH 304", ""],
        ["ARCH 315", "Technical Installations", 3, 3, 2, "Major Requirements", "R", "ARCH 302", ""],
        ["ARCH 401", "Architectural Design V", 5, 4, 1, "Major Requirements", "R", "ARCH 311", ""],
        ["ARCH 402", "Working Drawings", 3, 4, 1, "Major Requirements", "R", "ARCH 302;ARCH 311", ""],
        ["ARCH 403", "Lighting and Acoustics", 3, 4, 1, "Major Requirements", "R", "ARCH 315", ""],
        ["ARCH 404", "Housing and Human Settlements", 2, 4, 1, "Major Requirements", "R", "ARCH 312", ""],
        ["ARCH 405", "Humanities in Architecture", 2, 4, 1, "Major Requirements", "R", "ARCH 311", ""],
        ["ARCH 411", "Architectural Design VI", 5, 4, 2, "Major Requirements", "R", "ARCH 401", ""],
        ["ARCH 412", "Contract Documents", 3, 4, 2, "Major Requirements", "R", "ARCH 402", ""],
        ["ARCH 413", "Engineering Project Management", 3, 4, 2, "Major Requirements", "R", "ARCH 401", ""],
        ["ARCH 501", "Architectural Design VII", 5, 5, 1, "Major Requirements", "R", "ARCH 411", ""],
        ["ARCH 502", "Professional Practice I", 2, 5, 1, "Major Requirements", "R", "ARCH 411", ""],
        ["ARCH 503", "Graduation Project Research and Programming", 3, 5, 1, "Major Requirements", "R", "ARCH 411", ""],
        ["ARCH 511", "Graduation Project", 6, 5, 2, "Major Requirements", "R", "ARCH 501;ARCH 503", ""],
        ["ARCH 512", "Professional Practice II", 2, 5, 2, "Major Requirements", "R", "ARCH 502", ""],
        ["MEC 103", "Engineering Mechanics", 3, 2, 1, "College Requirements", "R", "PHY 103", ""],
        ["PHY 103", "Physics I", 4, 1, 2, "College Requirements", "R", "ORN 04R;ORN 04C", ""],
        ["ENG 101", "English Essay Writing", 3, 1, 2, "General Education", "R", "ORN 05R;ORN 05C", ""],
        ["ENG 201", "Technical Report Writing", 3, 3, 1, "General Education", "R", "ENG 101", ""],
        ["ISL 101", "Foundation of Islamic Culture", 2, 1, 1, "General Education", "R", "ORN 02R;ORN 02C", ""],
        ["ISL 202", "Financial Awareness", 2, 2, 2, "General Education", "R", "ORN 02R;ORN 02C", ""],
        ["ARB 102", "Communication Skills in Arabic", 2, 2, 2, "General Education", "R", "ORN 02R;ORN 02C", ""],
        ["ARB 202", "Writing Skills in Arabic", 2, 3, 2, "General Education", "R", "ORN 02R;ORN 02C", ""],
        ["CSK 001", "Career Skills", 0, 4, 2, "General Education", "R", "", ""],
    ] + GENED_HSS,
})

# ---------------------------------------------------------------- BSBA shared core
def bsba_core(major_code, nat_exam=None):
    rows = [
        ["ECO 101", "Principles of Microeconomics", 3, 1, 1, "University Requirements", "R", "ORN 04R;ORN 04C", ""],
        ["MGT 101", "Introduction to Management", 3, 1, 1, "University Requirements", "R", "ORN 04R;ORN 04C", ""],
        ["MTH 100", "Mathematics for Business", 3, 1, 1, "University Requirements", "R", "ORN 04R;ORN 04C", ""],
        ["ISL 101", "Foundation of Islamic Culture", 2, 1, 1, "University Requirements", "R", "ORN 02R;ORN 02C", ""],
        ["ARB 102", "Communication Skills in Arabic", 2, 1, 1, "University Requirements", "R", "ORN 02R;ORN 02C", ""],
        slot("Social Sciences / Humanity Elective I", 3, 1, 1, "Humanities / Social Science Electives"),
        ["ENG 101", "English Essay Writing", 3, 1, 2, "University Requirements", "R", "ORN 05R;ORN 05C", ""],
        ["ECO 105", "Principles of Macroeconomics", 3, 1, 2, "Business Core", "R", "ECO 101", ""],
        ["MTH 110", "Business Calculus", 3, 1, 2, "Business Core", "R", "MTH 100", ""],
        ["MIS 110", "Business Computing", 3, 1, 2, "Business Core", "R", "ORN 05R;ORN 05C", ""],
        ["ACC 201", "Financial Accounting", 3, 1, 2, "Business Core", "R", "ECO 101", ""],
        slot("Natural Science Elective", 3, 1, 2, "Natural Science Elective"),
        ["MKT 201", "Introduction to Marketing", 3, 2, 1, "Business Core", "R", "ECO 105", ""],
        ["MGT 220", "Organizational Behavior", 3, 2, 1, "Business Core", "R", "MGT 101;ECO 105", ""],
        ["MIS 201", "Introduction to MIS", 3, 2, 1, "Business Core", "R", "MIS 110", ""],
        ["FIN 202", "Introduction to Finance", 3, 2, 1, "Business Core", "R", "ACC 201", ""],
        ["MGT 210", "Business Communication", 3, 2, 1, "Business Core", "R", "ORN 05R;ORN 05C;MGT 101", ""],
        ["ACC 202", "Management Accounting", 3, 2, 1, "Business Core" if major_code != "ACC" else "Major Required", "R", "ACC 201", ""],
        ["ARB 202", "Writing Skills in Arabic", 2, 2, 2, "University Requirements", "R", "ORN 02R;ORN 02C", ""],
        ["ENG 202", "Tech Report and Business Writing", 3, 2, 2, "Business Core", "R", "ENG 101", ""],
        ["MGT 314", "Business Ethics and Social Responsibility", 3, 2, 2, "Business Core", "R", "MGT 220;ENG 101", ""],
        ["STT 201", "Business Statistics and Analysis", 3, 2, 2, "Business Core", "R", "MTH 110", ""],
        slot("Social Sciences / Humanity Elective II", 3, 2, 2, "Humanities / Social Science Electives"),
        ["MGT 306", "Legal Environment of Business", 3, 3, 1, "Business Core", "R", "MGT 220;ENG 101", ""],
        ["MGT 304", "Quantitative Methods for Business", 3, 3, 1, "Business Core", "R", "STT 201", ""],
        ["ISL 202", "Financial Awareness", 2, 3, 1, "University Requirements", "R", "ORN 02R;ORN 02C", ""],
        ["MGT 310", "Executive Seminar Series", 1, 3, 1, "Business Core", "R", "70 CH", ""],
        ["MGT 308", "Entrepreneurship and Innovation", 3, 3, 2, "Business Core", "R", "MGT 220;ENG 101", ""],
        ["MGT 330", "Operations Management", 3, 3, 2, "Business Core", "R", "MGT 304;ENG 101", ""],
        ["MGT 495", "Strategic Management", 3, 4, 1, "Business Core", "R", "MGT 330;100 CH", ""],
    ]
    if nat_exam:
        rows.append([nat_exam, "National Exam Review", 1, 4, 1, "Business Core", "R", "100 CH", ""])
    return rows

BUS_PLO = {
    "ACC": [
        ("PLO1", "Knowledge and Understanding", "Graduates will have a broad understanding of accounting theories, concepts, and technical accounting skills."),
        ("PLO2", "Knowledge and Understanding", "Graduates will have a solid background in accounting standards, professional ethics, and regulatory environment."),
        ("PLO3", "Skills", "Graduates will have the ability to apply knowledge, theories, and principles to real-life scenarios."),
        ("PLO4", "Skills", "Graduates will develop the ability to analyze information including professional accounting standards and conceptual framework for decision-making, both locally and internationally."),
        ("PLO5", "Skills", "Graduates will demonstrate sound decision-making abilities on a range of accounting problems using accounting principles, theories, and concepts."),
        ("PLO6", "Skills", "Graduates are expected to produce clear, concise business reports and communicate their work effectively in both oral and written forms."),
        ("PLO7", "Values, Autonomy, and Responsibility", "Graduates are committed life-long learners who work independently and/or in a team and demonstrate competent leadership."),
        ("PLO8", "Values, Autonomy, and Responsibility", "Graduates will demonstrate ethically and socially responsible behavior and make well-supported decisions in inter and/or multi-disciplinary and diverse environments."),
    ],
    "FIN": [
        ("PLO1", "Knowledge and Understanding", "Graduates will have a broad understanding of financial concepts and tools through study of core financial theories."),
        ("PLO2", "Knowledge and Understanding", "Graduates will have a theoretical understanding of local and international financial markets and their competitive environments."),
        ("PLO3", "Skills", "Graduates demonstrate financial managerial skills to apply knowledge, theories, models, and procedures to solve financial tasks."),
        ("PLO4", "Skills", "Graduates will have investment, portfolio management, and financial risk management skills."),
        ("PLO5", "Skills", "Graduates possess strong computational and quantitative skills enabling development of financial models for sound decisions."),
        ("PLO6", "Skills", "Graduates are expected to produce clear, concise business reports and communicate their work in various formats."),
        ("PLO7", "Values, Autonomy, and Responsibility", "Graduates are committed life-long learners functioning independently and collaboratively with demonstrated leadership competence."),
        ("PLO8", "Values, Autonomy, and Responsibility", "Graduates will demonstrate ethically and socially responsible behavior in diverse professional contexts."),
    ],
    "MGT": [
        ("PLO1", "Knowledge and Understanding", "Graduates will have sound knowledge of the contemporary management tools used for the effective management of modern organizations."),
        ("PLO2", "Knowledge and Understanding", "Graduates will have a comprehensive understanding of the local and international cultures in which contemporary organizations operate and compete for resources."),
        ("PLO3", "Skills", "Graduates will have the ability to apply knowledge, theories, and models to solve managerial tasks."),
        ("PLO4", "Skills", "Graduates will be able to conduct research to find sound solutions for business and management-related issues."),
        ("PLO5", "Skills", "Graduates will demonstrate strong analytical and management skills that allow them to analyze, interpret business problems, and make sound management decisions."),
        ("PLO6", "Skills", "Graduates are expected to produce clear, concise business reports and communicate their work effectively in both oral and written forms."),
        ("PLO7", "Values, Autonomy, and Responsibility", "Graduates are committed life-long learners who work independently and/or in a team and demonstrate competent leadership."),
        ("PLO8", "Values, Autonomy, and Responsibility", "Graduates will demonstrate ethically and socially responsible behavior that enables them to make well-supported decisions in diverse environments."),
    ],
    "MKT": [
        ("PLO1", "Knowledge and Understanding", "Graduates will be able to understand the key marketing concepts, principles, tools, and theories related to the marketing field and the modern practices of marketing."),
        ("PLO2", "Knowledge and Understanding", "Graduates will be able to describe marketing strategy, operation, and process in different marketing activities."),
        ("PLO3", "Skills", "Graduates will be able to apply marketing concepts, principles, and theories to solving related marketing problems."),
        ("PLO4", "Skills", "Graduates will be able to develop sound marketing strategies using key marketing concepts, such as segmentation, targeting, positioning, and differentiation."),
        ("PLO5", "Skills", "Graduates will be able to make informed decisions using critical thinking, marketing analytical tools, concepts, and theories."),
        ("PLO6", "Skills", "Graduates are expected to produce clear, concise business reports and communicate their work effectively in both oral and written forms."),
        ("PLO7", "Values, Autonomy, and Responsibility", "Graduates are committed life-long learners who work independently and/or in a team and demonstrate competent leadership."),
        ("PLO8", "Values, Autonomy, and Responsibility", "Graduates will demonstrate ethically and socially responsible behavior and make ethical marketing decisions."),
    ],
    "MIS": CAC_SOS + [("SO6", "Skills", "Support the delivery, use, and management of information systems within an information systems environment.")],
}

COLLEGE_ELECTIVES_ACC_FIN = [
    ("MGT 315", "Human Resource Management", "MGT 220"), ("MGT 317", "Family Business", "MGT 308"),
    ("MGT 321", "Organizational Leadership", "MGT 220"), ("MGT 331", "Compensation and Performance Management", "MGT 220"),
    ("MGT 429", "Training and Development", "MGT 315"), ("MGT 442", "International Business", "MGT 321"),
    ("MGT 305", "Quality Management", "MGT 220;ENG 101"), ("MGT 350", "Negotiations and Conflict Resolutions", "MGT 220;ENG 101"),
    ("MGT 360", "Project Management", "MGT 304"), ("MGT 422", "Logistic and Supply Chain Management", "MGT 304"),
    ("MGT 430", "Advanced Business Analytics", "MGT 304"), ("MGT 480", "Business Consulting", "90 CH"),
    ("MKT 311", "Consumer Behavior", "MKT 201"), ("MKT 318", "International Marketing", "MKT 201"),
    ("MKT 324", "Services Marketing", "MKT 311"), ("MKT 326", "Digital Marketing", "MKT 311"),
    ("MIS 308", "Artificial Intelligence for Business", "MIS 201"), ("MIS 316", "Fundamentals of Programming I", "MIS 201"),
    ("MIS 317", "Fundamentals of Web Design", "MIS 316"), ("MIS 318", "Data Analytics", "MIS 201"),
    ("MIS 326", "System Analysis and Design", "MIS 316"), ("MIS 327", "Database Management and Design", "MIS 316"),
    ("MIS 328", "Business Telecommunications", "MIS 201"),
]
GRAD_OPTION = [
    ["MGT 502", "Foundations of Leadership", 3, None, None, "Graduate Option (MBA courses)", "E", "90 CH", ""],
    ["MIS 504", "Information Systems", 3, None, None, "Graduate Option (MBA courses)", "E", "90 CH", ""],
    ["ECO 506", "Managerial Economics", 3, None, None, "Graduate Option (MBA courses)", "E", "90 CH", ""],
]
GRAD_OPTION_RULE = {"group": "Graduate Option (MBA courses)", "courses": 0, "credits": 9,
                    "condition": "Optional: students with ≥ 90 credits and cumulative GPA ≥ 3.60 may take up to 9 credits of designated MBA courses as business electives, with dean approval."}

def college_electives(exclude_prefixes):
    out = []
    for code, title, pre in COLLEGE_ELECTIVES_ACC_FIN:
        if code.split()[0] in exclude_prefixes:
            continue
        out.append([code, title, 3, None, None, "College Electives", "E", pre, ""])
    return out

def college_slots():
    return [slot("College Elective I", 3, 3, 1, "College Electives"), slot("College Elective II", 3, 3, 2, "College Electives"),
            slot("College Elective III", 3, 4, 1, "College Electives"), slot("College Elective IV", 3, 4, 1, "College Electives")]

def bsba(code, name, dept, url, major_rows, nat_exam, major_elective_group, with_college_list, college_slot_rows=True):
    rows = bsba_core(code, nat_exam) + major_rows + (college_slots() if college_slot_rows else [])
    if with_college_list:
        rows += college_electives({code})
        rows += GRAD_OPTION
    rules = [{"group": major_elective_group, "courses": 2, "credits": 6}] + ([{"group": "College Electives", "courses": 4, "credits": 12}] if college_slot_rows else []) + [{"group": "Humanities / Social Science Electives", "courses": 2, "credits": 6}]
    if with_college_list:
        rules.append(GRAD_OPTION_RULE)
    return {
        "code": code, "name": "Bachelor of Science in Business Administration – " + name, "short_name": name,
        "degree": "BSBA", "level": "Undergraduate", "department": dept, "total_credits": 127,
        "plan_version": "5.2", "plan_date": "2026-08-01", "source_url": url, "accreditation_note": "",
        "plos": BUS_PLO[code], "plo_source": "Program Learning Outcomes as published on the YU program page.",
        "elective_rules": rules, "courses": rows + GENED_HSS,
    }

programs.append(bsba("ACC", "Accounting", "AFD", "https://yu.edu.sa/wp-content/uploads/2026/08/5.2-Accounting-Program.pdf", [
    ["ACC 311", "Intermediate Accounting I", 3, 2, 2, "Major Required", "R", "ACC 201", ""],
    ["ACC 321", "Intermediate Accounting II", 3, 3, 1, "Major Required", "R", "ACC 311", ""],
    ["ACC 312", "Cost Accounting", 3, 3, 1, "Major Required", "R", "ACC 202", ""],
    ["ACC 326", "Zakat and Tax Accounting", 3, 3, 2, "Major Required", "R", "ACC 321", ""],
    ["ACC 418", "Advanced Financial Accounting", 3, 3, 2, "Major Required", "R", "ACC 321", ""],
    slot("Major Elective I", 3, 3, 2, "Accounting Major Electives"),
    ["ACC 430", "Auditing and Assurance Services", 3, 4, 1, "Major Required", "R", "ACC 321", ""],
    slot("Major Elective II", 3, 4, 1, "Accounting Major Electives"),
    ["ACC 498", "Co-op Training Internship", 6, 4, 2, "Internship", "R", "120 CH", ""],
    ["ACC 416", "Internal Audit and Control", 3, None, None, "Accounting Major Electives", "E", "ACC 321", ""],
    ["ACC 421", "Advanced Topics in Taxation", 3, None, None, "Accounting Major Electives", "E", "ACC 326", ""],
    ["ACC 424", "Accounting for Government and Non-Profit", 3, None, None, "Accounting Major Electives", "E", "ACC 321", ""],
    ["ACC 428", "Advanced Management Accounting", 3, None, None, "Accounting Major Electives", "E", "ACC 312", ""],
    ["ACC 432", "Financial Statement Analysis and Valuation", 3, None, None, "Accounting Major Electives", "E", "ACC 311", ""],
    ["ACC 434", "Accounting Information Systems", 3, None, None, "Accounting Major Electives", "E", "ACC 321", ""],
    ["ACC 440", "Accounting Theory and Practices", 3, None, None, "Accounting Major Electives", "E", "ACC 321", ""],
    # Finance major courses are college electives for Accounting students
    ["FIN 311", "Investment", 3, None, None, "College Electives", "E", "FIN 202", ""],
    ["FIN 313", "Financial Markets and Institutions", 3, None, None, "College Electives", "E", "FIN 202", ""],
    ["FIN 320", "Corporate Finance", 3, None, None, "College Electives", "E", "FIN 202", ""],
    ["FIN 335", "Fintech", 3, None, None, "College Electives", "E", "FIN 320", ""],
    ["FIN 325", "Islamic Finance", 3, None, None, "College Electives", "E", "FIN 202", ""],
], "ACC 401", "Accounting Major Electives", True))

programs.append(bsba("FIN", "Finance", "AFD", "https://yu.edu.sa/wp-content/uploads/2026/08/5.2-Finance-Program.pdf", [
    ["FIN 311", "Investment", 3, 2, 2, "Finance Major", "R", "FIN 202", ""],
    ["FIN 313", "Financial Markets and Institutions", 3, 3, 1, "Finance Major", "R", "FIN 202", ""],
    ["FIN 320", "Corporate Finance", 3, 3, 1, "Finance Major", "R", "FIN 202", ""],
    ["FIN 330", "Financial Modeling", 3, 3, 2, "Finance Major", "R", "FIN 320", ""],
    ["FIN 411", "Derivative Securities", 3, 3, 2, "Finance Major", "R", "FIN 311", ""],
    slot("Major Elective I", 3, 3, 2, "Finance Major Electives"),
    ["FIN 418", "International Finance", 3, 4, 1, "Finance Major", "R", "FIN 411", ""],
    slot("Major Elective II", 3, 4, 1, "Finance Major Electives"),
    ["FIN 498", "Co-op Training Internship", 6, 4, 2, "Internship", "R", "120 CH", ""],
    ["FIN 324", "Real Estate Finance", 3, None, None, "Finance Major Electives", "E", "FIN 311", ""],
    ["FIN 325", "Islamic Finance", 3, None, None, "Finance Major Electives", "E", "FIN 202", ""],
    ["FIN 335", "Fintech", 3, None, None, "Finance Major Electives", "E", "FIN 311", ""],
    ["FIN 340", "Blockchain Fundamentals", 3, None, None, "Finance Major Electives", "E", "FIN 202;MIS 201", ""],
    ["FIN 412", "Fixed Income Securities", 3, None, None, "Finance Major Electives", "E", "FIN 311", ""],
    ["FIN 414", "Portfolio Management", 3, None, None, "Finance Major Electives", "E", "FIN 311", ""],
    ["FIN 420", "Risk Management", 3, None, None, "Finance Major Electives", "E", "FIN 311", ""],
    ["ACC 311", "Intermediate Accounting I", 3, None, None, "College Electives", "E", "ACC 201", ""],
    ["ACC 312", "Cost Accounting", 3, None, None, "College Electives", "E", "ACC 202", ""],
    ["ACC 321", "Intermediate Accounting II", 3, None, None, "College Electives", "E", "ACC 311", ""],
    ["ACC 432", "Financial Statement Analysis and Valuation", 3, None, None, "College Electives", "E", "ACC 311", ""],
], "FIN 401", "Finance Major Electives", True))

programs.append(bsba("MGT", "Management", "MMD", "https://yu.edu.sa/wp-content/uploads/2026/08/5.2-Management-Program.pdf", [
    ["MGT 315", "Human Resource Management", 3, 2, 2, "Management Major", "R", "MGT 220", ""],
    ["MGT 321", "Organizational Leadership", 3, 3, 1, "Management Major", "R", "MGT 220", ""],
    ["MGT 331", "Compensation and Performance Management", 3, 3, 1, "Management Major", "R", "MGT 220", ""],
    ["MGT 410", "Change Management", 3, 3, 2, "Management Major", "R", "MGT 321", ""],
    ["MGT 429", "Training and Development", 3, 3, 2, "Management Major", "R", "MGT 315", ""],
    slot("Major Elective I", 3, 3, 2, "Management Major Electives"),
    ["MGT 442", "International Business", 3, 4, 1, "Management Major", "R", "MGT 321", ""],
    slot("Major Elective II", 3, 4, 1, "Management Major Electives"),
    ["MGT 498", "Co-op Training Internship", 6, 4, 2, "Internship", "R", "120 CH", ""],
    ["MGT 305", "Quality Management", 3, None, None, "Management Major Electives", "E", "MGT 220;ENG 101", ""],
    ["MGT 350", "Negotiations and Conflict Resolutions", 3, None, None, "Management Major Electives", "E", "MGT 220;ENG 101", ""],
    ["MGT 360", "Project Management", 3, None, None, "Management Major Electives", "E", "MGT 304", ""],
    ["MGT 422", "Logistic and Supply Chain Management", 3, None, None, "Management Major Electives", "E", "MGT 304", ""],
    ["MGT 430", "Advanced Business Analytics", 3, None, None, "Management Major Electives", "E", "MGT 304", ""],
    ["MGT 480", "Business Consulting", 3, None, None, "Management Major Electives", "E", "90 CH", ""],
], None, "Management Major Electives", False))

programs.append(bsba("MKT", "Marketing", "MMD", "https://yu.edu.sa/wp-content/uploads/2026/08/5.2-Marketing-Program.pdf", [
    ["MKT 311", "Consumer Behavior", 3, 2, 2, "Marketing Major", "R", "MKT 201", ""],
    ["MKT 318", "International Marketing", 3, 3, 1, "Marketing Major", "R", "MKT 201", ""],
    ["MKT 324", "Services Marketing", 3, 3, 1, "Marketing Major", "R", "MKT 311", ""],
    ["MKT 326", "Digital Marketing", 3, 3, 2, "Marketing Major", "R", "MKT 311", ""],
    ["MKT 411", "Marketing Strategies", 3, 3, 2, "Marketing Major", "R", "MKT 324", ""],
    slot("Major Elective I", 3, 3, 2, "Marketing Major Electives"),
    ["MKT 420", "Marketing Research", 3, 4, 1, "Marketing Major", "R", "MKT 411;MGT 304", ""],
    slot("Major Elective II", 3, 4, 1, "Marketing Major Electives"),
    ["MKT 498", "Co-op Training Internship", 6, 4, 2, "Internship", "R", "120 CH", ""],
    ["MKT 315", "Branding Strategy", 3, None, None, "Marketing Major Electives", "E", "MKT 201;ECO 105", ""],
    ["MKT 316", "Sales Management", 3, None, None, "Marketing Major Electives", "E", "MKT 201;ECO 105", ""],
    ["MKT 370", "Integrated Marketing Communications", 3, None, None, "Marketing Major Electives", "E", "MKT 324", ""],
    ["MKT 414", "Promotion and Advertising", 3, None, None, "Marketing Major Electives", "E", "MKT 318", ""],
    ["MKT 417", "Retail Management", 3, None, None, "Marketing Major Electives", "E", "MKT 311", ""],
], None, "Marketing Major Electives", False))

mis = bsba("MIS", "Management Information Systems", "MISD", "https://yu.edu.sa/wp-content/uploads/2026/08/5.2-MIS-Program.pdf", [
    # NOTE: the published plan prints MIS 316 as 3 CR in the Year-2 grid and 4 CR in the
    # "Major Required and Elective Courses" list. Both rows are kept so SAQF's sync detects it.
    ["MIS 316", "Fundamentals of Programming I", 3, 2, 2, "MIS Major Required", "R", "MIS 201", ""],
    ["MIS 328", "Business Telecommunications", 3, 2, 2, "MIS Major Required", "R", "MIS 201", ""],
    ["MIS 317", "Fundamentals of Web Design", 3, 3, 1, "MIS Major Required", "R", "MIS 316", ""],
    ["MIS 326", "System Analysis and Design", 3, 3, 1, "MIS Major Required", "R", "MIS 316", ""],
    ["MIS 327", "Database Management and Design", 3, 3, 1, "MIS Major Required", "R", "MIS 316", ""],
    ["MIS 329", "Decision Support and Business Intelligence", 3, 3, 2, "MIS Major Required", "R", "MIS 327", ""],
    ["MIS 423", "Web Based Application", 3, 3, 2, "MIS Major Required", "R", "MIS 327;MIS 317", ""],
    ["MIS 431", "Project Management", 3, 3, 2, "MIS Major Required", "R", "MIS 326", ""],
    ["MIS 427", "Information Security Risk Management", 3, 4, 1, "MIS Major Required", "R", "MIS 328", ""],
    ["MIS 492", "MIS Senior Project", 3, 4, 1, "MIS Major Required", "R", "100 CH", ""],
    slot("Major Elective I", 3, 3, 2, "MIS Major Electives"),
    slot("Major Elective II", 3, 4, 1, "MIS Major Electives"),
    ["MIS 498", "Co-op Training Internship", 6, 4, 2, "Internship", "R", "120 CH", ""],
    ["MIS 308", "Artificial Intelligence for Business", 3, None, None, "MIS Major Electives", "E", "MIS 201", ""],
    ["MIS 318", "Data Analytics", 3, None, None, "MIS Major Electives", "E", "MIS 201", ""],
    ["MIS 428", "Healthcare Information System", 3, None, None, "MIS Major Electives", "E", "MIS 327", ""],
    ["MIS 429", "Data Mining and Analysis", 3, None, None, "MIS Major Electives", "E", "MIS 329", ""],
    ["MIS 430", "Advanced Topics of Information Systems", 3, None, None, "MIS Major Electives", "E", "MIS 327", ""],
    ["MIS 432", "Enterprise Systems", 3, None, None, "MIS Major Electives", "E", "MIS 327", ""],
    ["MIS 433", "Int. B. and Web Applications Development", 3, None, None, "MIS Major Electives", "E", "MIS 316", ""],
    ["MIS 434", "Human Resource Information Systems", 3, None, None, "MIS Major Electives", "E", "MIS 327", ""],
    ["MIS 435", "Knowledge Management Systems", 3, None, None, "MIS Major Electives", "E", "MIS 201", ""],
    ["MIS 436", "Mobile Computing", 3, None, None, "MIS Major Electives", "E", "MIS 327", ""],
], "MIS 401", "MIS Major Electives", False, college_slot_rows=False)
mis["source_variants"] = [{"code": "MIS 316", "field": "credits", "value": 4,
                           "where": "'Major Required and Elective Courses' list in the same published plan"}]
programs.append(mis)

# ---------------------------------------------------------------- Postgraduate
programs.append({
    "code": "MBA", "name": "Master of Business Administration", "short_name": "MBA",
    "degree": "MBA", "level": "Postgraduate", "department": "MMD", "total_credits": 42,
    "plan_version": "1.6", "plan_date": "2025-02-01",
    "source_url": "https://yu.edu.sa/wp-content/uploads/2025/02/1.6-MBA-Study-Plan.pdf", "accreditation_note": "",
    "plos": [
        ("PLO1", "Knowledge and Understanding", "Demonstrate knowledge and understanding of concepts, theories, tools, and practices of business administration."),
        ("PLO2", "Skills", "Use integrative business knowledge to solve complex business problems."),
        ("PLO3", "Skills", "Apply qualitative and quantitative reasoning to problem-solving models."),
        ("PLO4", "Skills", "Apply core business principles in business decision-making."),
        ("PLO5", "Skills", "Demonstrate a professional level of communication in verbal and written forms."),
        ("PLO6", "Values, Autonomy, and Responsibility", "Demonstrate the interpersonal skills needed to be effective leaders and team members."),
        ("PLO7", "Values, Autonomy, and Responsibility", "Incorporate knowledge of ethics and corporate social responsibility into decision-making."),
    ],
    "plo_source": "Learning objectives as published on the YU MBA page (condensed wording).",
    "elective_rules": [{"group": "MBA Electives", "courses": 4, "credits": 12}],
    "courses": [
        ["PGRD 495", "Fundamentals of Accounting and Finance", 3, 0, 0, "Pre-MBA Foundation", "R", "", ""],
        ["PGRD 496", "Fundamentals of Business Statistics", 3, 0, 0, "Pre-MBA Foundation", "R", "", ""],
        ["MGT 502", "Foundations of Leadership", 3, 1, 1, "Core", "R", "", ""],
        ["MIS 504", "Information Systems", 3, 1, 1, "Core", "R", "", ""],
        ["ECO 506", "Managerial Economics", 3, 1, 1, "Core", "R", "", ""],
        ["ACC 503", "Financial Accounting", 3, 1, 2, "Core", "R", "PGRD 495", ""],
        ["MKT 506", "Marketing Management", 3, 1, 2, "Core", "R", "", ""],
        ["MGT 508", "Organizational Theory and Behavior", 3, 1, 2, "Core", "R", "", ""],
        ["STT 503", "Quantitative Business Analysis", 3, 2, 1, "Core", "R", "PGRD 496", ""],
        ["FIN 503", "Managerial Finance", 3, 2, 1, "Core", "R", "ACC 503", ""],
        ["MGT 555", "Research Project", 3, 2, 1, "Core", "R", "18 CH", ""],
        slot("MBA Elective I", 3, 2, 1, "MBA Electives"),
        ["MGT 512", "Strategic Management", 3, 2, 2, "Core", "R", "21 CH", ""],
        slot("MBA Elective II", 3, 2, 2, "MBA Electives"),
        slot("MBA Elective III", 3, 2, 2, "MBA Electives"),
        slot("MBA Elective IV", 3, 2, 2, "MBA Electives"),
        ["PMT 554", "Project Management Strategies", 3, None, None, "MBA Electives", "E", "MGT 502", ""],
        ["MGT 521", "Human Resources Management", 3, None, None, "MBA Electives", "E", "MGT 502", ""],
        ["MGT 535", "International Business", 3, None, None, "MBA Electives", "E", "", ""],
        ["MKT 553", "Consumer Behavior", 3, None, None, "MBA Electives", "E", "MKT 506", ""],
        ["MGT 531", "Business Ethics", 3, None, None, "MBA Electives", "E", "MGT 508", ""],
        ["MGT 541", "International Management", 3, None, None, "MBA Electives", "E", "", ""],
        ["ENT 554", "Entrepreneurship – Corporate Ventures and Startups", 3, None, None, "MBA Electives", "E", "", ""],
    ],
})

programs.append({
    "code": "EMBA", "name": "Executive Master of Business Administration", "short_name": "EMBA",
    "degree": "EMBA", "level": "Postgraduate", "department": "MMD", "total_credits": 42,
    "plan_version": "1.6", "plan_date": "2025-02-01",
    "source_url": "https://yu.edu.sa/wp-content/uploads/2025/02/1.6-EMBA-Study-Plan-1.pdf", "accreditation_note": "",
    "plos": [], "plo_source": "No PLO statements found on the public program page.",
    "elective_rules": [{"group": "EMBA Electives", "courses": 4, "credits": 8}],
    "courses": [
        ["PGRD 495", "Fundamentals of Accounting and Finance", 3, 0, 0, "Foundation", "R", "", ""],
        ["PGRD 496", "Fundamentals of Business Statistics", 3, 0, 0, "Foundation", "R", "", ""],
        ["STT 505", "Management Statistics", 2, 1, 1, "Core", "R", "PGRD 496", ""],
        ["BUS 540", "Business Research Methods", 2, 1, 1, "Core", "R", "", ""],
        ["MGT 525", "Organization Behavior & Leadership", 2, 1, 1, "Core", "R", "", ""],
        ["MKT 515", "Marketing Management", 2, 1, 1, "Core", "R", "", ""],
        ["MGT 530", "Management Ethics and Law", 2, 1, 1, "Core", "R", "", ""],
        ["ACC 505", "Financial Accounting", 2, 1, 2, "Core", "R", "PGRD 495", ""],
        ["ECO 504", "International Economics", 2, 1, 2, "Core", "R", "", ""],
        ["HRM 510", "Human Resources Management", 2, 1, 2, "Core", "R", "", ""],
        ["MGT 511", "Operations Management", 2, 1, 2, "Core", "R", "", ""],
        ["MGT 540", "International Management", 2, 1, 2, "Core", "R", "", ""],
        ["BUS 535", "International Business", 2, 2, 1, "Core", "R", "", ""],
        ["BUS 536", "Business Feasibility Study", 2, 2, 1, "Core", "R", "ECO 504", ""],
        ["MGT 545", "IT for Managers", 2, 2, 1, "Core", "R", "", ""],
        ["FIN 505", "Financial Management", 2, 2, 1, "Core", "R", "ACC 505", ""],
        ["MGT 590", "Business Plan", 4, 2, 1, "Core", "R", "18 CH", ""],
        ["MGT 507", "Strategic Management", 2, 2, 2, "Core", "R", "20 CH", ""],
        slot("EMBA Elective I", 2, 2, 2, "EMBA Electives"), slot("EMBA Elective II", 2, 2, 2, "EMBA Electives"),
        slot("EMBA Elective III", 2, 2, 2, "EMBA Electives"), slot("EMBA Elective IV", 2, 2, 2, "EMBA Electives"),
    ],
})

programs.append({
    "code": "MCS", "name": "Master in Cyber Security", "short_name": "Cyber Security (MCS)",
    "degree": "MSc", "level": "Postgraduate", "department": "CED", "total_credits": 30,
    "plan_version": "2026", "plan_date": "2026-07-01",
    "source_url": "https://yu.edu.sa/wp-content/uploads/2026/07/MCS-Study-Plan.pdf", "accreditation_note": "",
    "plos": [
        ("K1", "Knowledge and Understanding", "Identify solutions of broadly-defined cybersecurity problems."),
        ("K2", "Knowledge and Understanding", "Monitor web-based systems through widely accepted standards, procedures and policies."),
        ("K3", "Knowledge and Understanding", "Maintain web-based systems through widely accepted standards, procedures and policies."),
        ("S1", "Skills", "Analyze a broadly-defined cybersecurity problem."),
        ("S2", "Skills", "Apply the principles of cybersecurity and other relevant disciplines."),
        ("S3", "Skills", "Apply cybersecurity principles and practices to maintain operations in the presence of risks and threats."),
        ("S4", "Skills", "Enhance the protection of web-based systems through widely accepted standards, procedures and policies."),
        ("S5", "Skills", "Conduct risk and vulnerability assessments of existing and proposed systems."),
        ("S6", "Skills", "Communicate effectively in a variety of professional contexts."),
        ("V1", "Values, Autonomy, and Responsibility", "Recognize professional responsibilities and make informed judgments in cybersecurity practice based on legal and ethical principles."),
        ("V2", "Values, Autonomy, and Responsibility", "Function effectively as a member or leader of a team engaged in cybersecurity activities."),
    ],
    "plo_source": "PLOs as published on the YU MCS page.",
    "elective_rules": [{"group": "MCS Electives", "courses": 2, "credits": 6}],
    "courses": [
        ["PRQ 401", "Comprehensive Cybersecurity – Foundations and Threats", 3, 0, 0, "Foundation (if required)", "R", "", ""],
        ["PRQ 402", "Foundations of Computer Networking and Security", 3, 0, 0, "Foundation (if required)", "R", "", ""],
        ["PRQ 403", "Operating Systems Concepts", 3, 0, 0, "Foundation (if required)", "R", "", ""],
        ["CYB 511", "Cybersecurity Planning and Management", 3, 1, 1, "Core", "R", "PRQ 401", ""],
        ["CYB 512", "Digital Forensics and Incident Management", 3, 1, 1, "Core", "R", "PRQ 403", ""],
        ["CYB 513", "Information Assurance Architectures and Standards", 3, 1, 1, "Core", "R", "PRQ 402", ""],
        ["CYB 514", "Penetration Testing and Ethical Hacking", 3, 1, 2, "Core", "R", "CYB 513", ""],
        ["CYB 515", "Vulnerability Assessment", 3, 1, 2, "Core", "R", "CYB 512", ""],
        ["CYB 516", "Advanced Cryptography", 3, 1, 2, "Core", "R", "6 CH", ""],
        ["CYB 530", "Project I", 3, 2, 1, "Core", "R", "12 CH", ""],
        slot("MCS Elective I", 3, 2, 1, "MCS Electives"),
        ["CYB 531", "Project II", 3, 2, 2, "Core", "R", "CYB 530", ""],
        slot("MCS Elective II", 3, 2, 2, "MCS Electives"),
        ["CYB 520", "Selected Topics in Cybersecurity", 3, None, None, "MCS Electives", "E", "CYB 516", ""],
        ["CYB 521", "Software Security", 3, None, None, "MCS Electives", "E", "CYB 513", ""],
        ["CYB 522", "Cybersecurity Policies and Procedures", 3, None, None, "MCS Electives", "E", "CYB 513", ""],
        ["CYB 523", "Cloud Security", 3, None, None, "MCS Electives", "E", "CYB 516", ""],
        ["CYB 524", "Security Risk Analysis", 3, None, None, "MCS Electives", "E", "CYB 511", ""],
        ["CYB 525", "Cybersecurity Design Principles", 3, None, None, "MCS Electives", "E", "CYB 513", ""],
    ],
})

programs.append({
    "code": "LLB", "name": "Bachelor of Laws", "short_name": "Law (LL.B)",
    "degree": "LLB", "level": "Undergraduate", "department": "LAWD", "total_credits": 131,
    "plan_version": "2024", "plan_date": "2024-12-01",
    "source_url": "https://yu.edu.sa/wp-content/uploads/2024/12/LLB-YU.pdf", "accreditation_note": "",
    "plos": [], "plo_source": "No PLO statements found on the public program page.",
    "elective_rules": [{"group": "Law Electives", "courses": 2, "credits": 6}, {"group": "Humanities / Social Science Electives", "courses": 2, "credits": 6}],
    "courses": [
        ["LAW 101", "Introduction to Law", 3, 1, 1, "Major Requirements", "R", "ORN 04R;ORN 04C", ""],
        ["ENG 101", "English Essay Writing", 3, 1, 1, "University Requirements", "R", "ORN 05R;ORN 05C", ""],
        ["ISL 101", "Foundation of Islamic Culture", 2, 1, 1, "University Requirements", "R", "ORN 02R;ORN 02C", ""],
        ["ARB 102", "Communication Skills in Arabic", 2, 1, 1, "University Requirements", "R", "ORN 02R;ORN 02C", ""],
        ["MGT 101", "Introduction to Management", 3, 1, 1, "College Requirements", "R", "ORN 04R;ORN 04C", ""],
        slot("Humanities / Social Science Elective I", 3, 1, 1, "Humanities / Social Science Electives"),
        ["LAW 116", "Legal Writing", 3, 1, 2, "Major Requirements", "R", "LAW 101;ORN 05R;ORN 05C", ""],
        ["LAW 117", "Usul Al-Fiqh (Jurisprudential Principles)", 3, 1, 2, "Major Requirements", "R", "ORN 04R;ORN 04C", ""],
        ["LAW 118", "Constitutional Law", 3, 1, 2, "Major Requirements", "R", "LAW 101", ""],
        ["LAW 119", "Professional Responsibilities", 2, 1, 2, "Major Requirements", "R", "LAW 101", ""],
        ["ISL 201", "Foundation of Islamic Economy", 2, 1, 2, "University Requirements", "R", "ORN 02R;ORN 02C", ""],
        ["ARB 202", "Writing Skills in Arabic", 2, 1, 2, "University Requirements", "R", "ORN 02R;ORN 02C", ""],
        ["LAW 115", "Family Law", 2, 1, 2, "Major Requirements", "R", "ORN 03R;ORN 03C", ""],
        slot("Humanities / Social Science Elective II", 3, 1, 2, "Humanities / Social Science Electives"),
        ["LAW 201", "Sources of Obligations", 3, 2, 1, "Major Requirements", "R", "LAW 101;ORN 05R;ORN 05C", ""],
        ["LAW 202", "Administrative Law (1)", 3, 2, 1, "Major Requirements", "R", "LAW 101;ORN 05R;ORN 05C", ""],
        ["LAW 203", "Public International Law (1)", 3, 2, 1, "Major Requirements", "R", "LAW 101;ORN 05R;ORN 05C", ""],
        ["LAW 204", "Juristic Rules in Islamic Jurisprudence", 3, 2, 1, "Major Requirements", "R", "LAW 117", ""],
        ["LAW 205", "Criminal Law (1)", 3, 2, 1, "Major Requirements", "R", "LAW 101", ""],
        slot("Law Elective I", 3, 2, 1, "Law Electives"),
        ["LAW 218", "Administrative Law (2)", 3, 2, 2, "Major Requirements", "R", "LAW 202", ""],
        ["LAW 229", "Public International Law (2)", 2, 2, 2, "Major Requirements", "R", "LAW 203", ""],
        ["LAW 230", "Criminal Law (2)", 3, 2, 2, "Major Requirements", "R", "LAW 205", ""],
        ["LAW 231", "Effects of Obligations", 3, 2, 2, "Major Requirements", "R", "LAW 201", ""],
        ["LAW 235", "Property Law", 3, 2, 2, "Major Requirements", "R", "LAW 101;30 CH", ""],
        slot("Law Elective II", 3, 2, 2, "Law Electives"),
        ["LAW 301", "Commercial Law", 3, 3, 1, "Major Requirements", "R", "LAW 116;30 CH", ""],
        ["LAW 302", "Labor Law & Social Security", 3, 3, 1, "Major Requirements", "R", "LAW 101;30 CH", ""],
        ["LAW 303", "Civil Contracts", 3, 3, 1, "Major Requirements", "R", "LAW 231", ""],
        ["LAW 304", "Civil & Commercial Procedures", 3, 3, 1, "Major Requirements", "R", "LAW 116;30 CH", ""],
        ["LAW 308", "Real Estate & Personal Guarantees", 3, 3, 1, "Major Requirements", "R", "LAW 235", ""],
        ["LAW 309", "Islamic Regulations of Inheritance, Endowments & Wills", 3, 3, 1, "Major Requirements", "R", "LAW 115", ""],
        ["LAW 314", "Evidence", 3, 3, 2, "Major Requirements", "R", "LAW 304", ""],
        ["LAW 316", "Company Law", 3, 3, 2, "Major Requirements", "R", "LAW 301", ""],
        ["LAW 317", "Commercial Contracts & Banking Operations", 3, 3, 2, "Major Requirements", "R", "LAW 301", ""],
        ["LAW 326", "Criminal Procedure Law", 3, 3, 2, "Major Requirements", "R", "LAW 230", ""],
        ["LAW 327", "Administrative Judiciary", 3, 3, 2, "Major Requirements", "R", "LAW 218", ""],
        ["LAW 328", "Alternative Dispute Resolution", 3, 3, 2, "Major Requirements", "R", "LAW 304", ""],
        ["LAW 422", "Maritime & Air Law", 3, 4, 1, "Major Requirements", "R", "LAW 301", ""],
        ["LAW 424", "Private International Law", 3, 4, 1, "Major Requirements", "R", "LAW 101;60 CH", ""],
        ["LAW 425", "Commercial Papers & Bankruptcy", 3, 4, 1, "Major Requirements", "R", "LAW 301", ""],
        ["LAW 427", "Law of Execution", 3, 4, 1, "Major Requirements", "R", "LAW 314", ""],
        ["LAW 429", "Intellectual Property", 3, 4, 1, "Major Requirements", "R", "70 CH", ""],
        ["LAW 430", "Zakat & Taxation Law", 3, 4, 1, "Major Requirements", "R", "LAW 316", ""],
        ["LAW 411", "Training and Research", 6, 4, 2, "Major Requirements", "R", "90 CH", ""],
    ] + GENED_HSS,
})

programs.append({
    "code": "LLM", "name": "Master of Laws in Business Law", "short_name": "Business Law (LL.M)",
    "degree": "LLM", "level": "Postgraduate", "department": "LAWD", "total_credits": 36,
    "plan_version": "2022-23", "plan_date": "2022-08-01",
    "source_url": "https://yu.edu.sa/wp-content/uploads/2022/08/New-LLM-study-plan-2022-23.pdf", "accreditation_note": "",
    "plos": [], "plo_source": "No PLO statements found on the public program page.",
    "elective_rules": [{"group": "LL.M Electives", "courses": 2, "credits": 4}],
    "courses": [
        ["LAW 460", "Introduction to Legal Studies", 0, 0, 0, "Foundation (if required)", "R", "", ""],
        ["LAW 461", "Sources of Obligation", 0, 0, 0, "Foundation (if required)", "R", "", ""],
        ["LAW 510", "Research Skills & Methods", 3, 1, 1, "Core", "R", "", ""],
        ["LAW 512", "International Business Law", 3, 1, 1, "Core", "R", "", ""],
        ["LAW 514", "Corporate Law", 3, 1, 1, "Core", "R", "", ""],
        ["LAW 530", "Banking Law", 3, 1, 2, "Core", "R", "", ""],
        ["LAW 521", "Insolvency Law", 3, 1, 2, "Core", "R", "", ""],
        ["LAW 520", "Capital Market Regulation", 3, 1, 2, "Core", "R", "", ""],
        ["LAW 525", "International Intellectual Property Law", 3, 2, 1, "Core", "R", "", ""],
        ["LAW 596", "Independent Research 1", 3, 2, 1, "Core", "R", "", ""],
        ["LAW 536", "Investment Law", 3, 2, 1, "Core", "R", "", ""],
        ["LAW 537", "International Commercial Arbitration", 3, 2, 2, "Core", "R", "", ""],
        slot("Law Elective I", 2, 2, 2, "LL.M Electives"), slot("Law Elective II", 2, 2, 2, "LL.M Electives"),
        ["LAW 597", "Independent Research 2", 2, 2, 2, "Core", "R", "LAW 596", ""],
    ],
})

# Catalog descriptions (subset). source = where the text came from.
descriptions = {
    "SWE 401": ("Quality assurance as an activity that runs through the entire development process: understanding the needs of clients and users, analyzing and documenting requirements, quality planning, reviews, and quality models such as CMM and ISO 9000.", "YU course descriptions PDF (condensed)"),
    "SWE 302": ("Introduction to software architecture and design including design patterns, multilayer architecture, client-server, and Model-View-Controller, with focus on patterns, frameworks, and component-based engineering.", "YU course descriptions PDF (condensed)"),
    "SWE 301": ("Functional and non-functional requirements; use case modeling; specifying functional requirements using use cases; translating business requirements into specification documents.", "YU course descriptions PDF (condensed)"),
    "SWE 300": ("Object-oriented modelling techniques for analysis and design using the Unified Modelling Language (UML), the standard notation for object-oriented analysis and design.", "YU course descriptions PDF (condensed)"),
    "SWE 202": ("Introduces software engineering through the key development processes, including life cycles, requirements analysis, architectural design, and testing.", "YU course descriptions PDF (condensed)"),
    "SWE 312": ("Construction of working, meaningful software through a combination of coding and rapid system prototyping, including event-driven simulation and user-interface evaluation.", "YU course descriptions PDF (condensed)"),
    "SWE 411": ("Strategies of software testing, covering state-of-the-art techniques to prepare students as proficient testers.", "YU course descriptions PDF (condensed)"),
    "SWE 322": ("Design and development of dynamic, database-driven web sites using a PHP framework with hands-on exercises in web application development.", "YU course descriptions PDF (condensed)"),
    "CIS 491": ("First capstone course in which students submit their project/research proposal during the first semester of the fourth year.", "YU course descriptions PDF (condensed)"),
    "CIS 492": ("Second capstone course in which students complete the projects started in CIS 491.", "YU course descriptions PDF (condensed)"),
    "SWE 413": ("Concepts and principles of object-oriented design through design patterns, covering creational, structural, and behavioral patterns with refactoring examples.", "YU course descriptions PDF (condensed)"),
    "SWE 321": ("User interface design techniques for web and mobile applications, including wireframing.", "YU course descriptions PDF (condensed)"),
    "CIS 443": ("Cloud service and deployment models, virtualization, cloud storage and networking, and the design and operation of applications on cloud platforms.", "PROTOTYPE SEED — not in the published description PDF; replace from registrar catalog"),
    "SWE 412": ("Design and development of mobile applications, covering platform architecture, user interface patterns, data persistence, networking, and testing and publishing of mobile apps.", "PROTOTYPE SEED — not in the published description PDF; replace from registrar catalog"),
}

def parse_prereq(text):
    toks = [t.strip() for t in re.split(r";", text or "") if t.strip()]
    courses, thresholds, prep = [], [], []
    for t in toks:
        m = re.match(r"^(\d+)\s*CH$", t)
        if m:
            thresholds.append(int(m.group(1)))
        elif t.startswith("ORN"):
            prep.append(t)
        else:
            courses.append(t)
    return courses, thresholds, prep

for p in programs:
    plan = []
    for r in p["courses"]:
        code, title, credits, year, sem, group, kind, pre, co = r
        courses, thresholds, prep = parse_prereq(pre)
        co_courses, _, _ = parse_prereq(co)
        entry = {"code": None if code == "SLOT" else code, "title": title, "credits": credits,
                 "year": year, "semester": sem, "group": group,
                 "type": "required" if kind == "R" else "elective",
                 "slot": code == "SLOT",
                 "prerequisites": courses, "credit_threshold": max(thresholds) if thresholds else None,
                 "preparatory": prep, "corequisites": co_courses}
        plan.append(entry)
    p["courses"] = plan
    p["plos"] = [{"code": c, "domain": d, "text": t} for c, d, t in p["plos"]]
    with open(os.path.join(OUT, "programs", p["code"].lower() + ".json"), "w") as f:
        json.dump(p, f, indent=1, ensure_ascii=False)

institution["descriptions"] = {k: {"text": v[0], "source": v[1]} for k, v in descriptions.items()}
institution["programs"] = [p["code"] for p in programs]
with open(os.path.join(OUT, "institution.json"), "w") as f:
    json.dump(institution, f, indent=1, ensure_ascii=False)

# Quick stats
codes = set()
for p in programs:
    for c in p["courses"]:
        if c["code"]:
            codes.add(c["code"])
print(len(programs), "programs;", len(codes), "distinct catalog courses")
