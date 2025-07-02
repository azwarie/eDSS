<script>
    // Format trends data for Cal-Heatmap
    const formattedData = {};
    Object.keys(trendsData).forEach(date => {
        const timestamp = new Date(date).getTime() / 1000; // Convert to Unix timestamp
        formattedData[timestamp] = trendsData[date];
    });

    // Initialize Cal-Heatmap
    const cal = new CalHeatmap();
    cal.paint({
        itemSelector: "#admissionsHeatmap",
        domain: "month",
        subDomain: "day",
        data: formattedData,
        range: 1,
        cellSize: 15,
        domainGutter: 10,
        tooltip: true,
        subDomainTextFormat: (date, value) => {
            if (!value) return "";
            return `A: ${value.admissions || 0}, D: ${value.discharges || 0}`;
        },
        legend: [1, 5, 10, 20],
        legendColors: {
            min: "#D1F2EB",
            max: "#117864",
            empty: "#E5E8E8"
        }
    });
</script>
